<?php
namespace ide;

use ide\bundle\AbstractBundle;
use ide\editors\AbstractEditor;
use ide\editors\value\ElementPropertyEditor;
use ide\formats\AbstractFormat;
use ide\formats\IdeFormatOwner;
use ide\forms\MainForm;
use ide\forms\MainWindowForm;
use ide\l10n\IdeLocalizer;
use ide\l10n\L10n;
use ide\library\IdeLibrary;
use ide\misc\AbstractCommand;
use ide\misc\EventHandlerBehaviour;
use ide\project\AbstractProjectSupport;
use ide\project\AbstractProjectTemplate;
use ide\project\control\AbstractProjectControlPane;
use ide\project\Project;
use ide\settings\ExtensionsSettings;
use ide\settings\IdeSettings;
use ide\settings\SettingsContainer;
use ide\systems\Cache;
use ide\systems\FileSystem;
use ide\systems\IdeSystem;
use ide\systems\ProjectSystem;
use ide\themes\DarkTheme;
use ide\themes\LightTheme;
use ide\themes\ThemeManager;
use ide\tool\IdeToolManager;
use ide\ui\LazyLoadingImage;
use ide\ui\UXError;
use ide\utils\FileUtils;
use ide\utils\Json;
use php\gui\framework\Application;
use php\gui\UXButton;
use php\gui\UXImage;
use php\gui\UXImageView;
use php\gui\UXMenu;
use php\gui\UXMenuItem;
use php\gui\UXSeparator;
use php\io\File;
use php\io\IOException;
use php\io\ResourceStream;
use php\io\Stream;
use php\lang\IllegalArgumentException;
use php\lang\Process;
use php\lang\System;
use php\lang\Thread;
use php\lang\ThreadPool;
use php\lib\arr;
use php\lib\fs;
use php\lib\reflect;
use php\lib\Str;
use php\time\Time;
use php\time\Timer;
use php\util\Regex;
use php\util\Scanner;
use timer\AccurateTimer;


/**
 * Class Ide
 * @package ide
 */
class Ide extends Application
{
    use EventHandlerBehaviour;
    use IdeFormatOwner {
        getRegisteredFormat as _getRegisteredFormat;
    }

    /** @var string */
    private $OS;

    /**
     * @var Ide
     */
    static private $ide;

    /**
     * @var AbstractProjectTemplate[]
     */
    protected $projectTemplates = [];

    /**
     * @var AbstractProjectSupport[]
     */
    protected $projectSupports = [];

    /**
     * @var AbstractExtension[]
     */
    protected $extensions = [];

    /**
     * @var AbstractCommand[]
     */
    protected $commands = [];

    /**
     * @var callable
     */
    protected $afterShow = [];

    /**
     * @var IdeConfiguration[]
     */
    protected $configurations = [];

    /**
     * @var Project
     */
    protected $openedProject = null;

    /**
     * @var AbstractProjectControlPane[]
     */
    protected $projectControlPanes = [];

    /**
     * @var IdeLibrary
     */
    protected $library;

    /**
     * @var IdeToolManager
     */
    protected $toolManager;

    /**
     * @var boolean
     */
    protected $idle = false;

    /**
     * @var IdeLocalizer
     */
    protected $localizer;

    /**
     * @var IdeLanguage[]
     */
    protected $languages = [];

    /**
     * @var IdeLanguage
     */
    protected $language;

    /**
     * @var ThreadPool
     */
    private $asyncThreadPool;


    protected $disableOpenLastProject = false;

    /**
     * @var string
     */
    protected $mode = 'dev';

    /**
     * @var ThemeManager
     */
    private $themeManager;

    /**
     * @var SettingsContainer
     */
    private $settingsContainer;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var MainForm
     */
    private $mainForm;

    public function __construct($configPath = null)
    {
        parent::__construct($configPath);
        static::$ide = $this;

        $this->OS = IdeSystem::getOs();
        $this->mode = IdeSystem::getMode();

        $this->library = new IdeLibrary($this);
        $this->toolManager = new IdeToolManager();
        $this->localizer = new IdeLocalizer();
        $this->localizer->setUseDefaultValuesForLang('ru');

        $this->themeManager = new ThemeManager();
        $this->themeManager->register($l = new LightTheme);
        $this->themeManager->register(new DarkTheme);

        $this->settingsContainer = new SettingsContainer();
        $this->settingsContainer->register(new IdeSettings);
        $this->settingsContainer->register(new ExtensionsSettings);

        $this->asyncThreadPool = ThreadPool::createCached();

        foreach ([
            $this->getLibrary()->getResourceDirectory("platforms"),
            $this->getLibrary()->getResourceDirectory("plugins")
                 ] as $path)
            fs::scan($path, function (string $path) {
                if (fs::ext($path) == "jar") System::addClassPath($path);
            });

        foreach ($GLOBALS['argv'] as $arg) if (class_exists($arg, true) && (new $arg) instanceof AbstractPlatform) $this->platform = new $arg;

        if (!$this->platform) $this->platform = new IdeStandardPlatform();
    }

    public function launch()
    {
        parent::launch(
            function () {
                Logger::reset();
                Logger::info("Start IDE, mode = $this->mode, os = $this->OS, version = {$this->getVersion()}");
                Logger::info(str::format("Commands Args = [%s]", str::join((array)$GLOBALS['argv'], ', ')));

                set_exception_handler(function ($e) {
                    Logger::exception($e->getMessage(), $e);

                    $alert = new UXError($e);
                    $alert->showAndWait();
                });

                $this->readLanguages();

                $this->platform->onIdeStart();
            },
            function () {
                $this->mainForm = new MainForm();
                $this->setOpenedProject(null);

                foreach ($this->afterShow as $handle) {
                    $handle();
                }

                $this->registerAll();

                foreach ($this->extensions as $extension) {
                    $extension->onIdeStart();
                }

                $timer = new AccurateTimer(1000, function () {
                    Ide::async(function () {
                        foreach (FileSystem::getOpened() as $info) {
                            if (!fs::exists($info['file'])) {
                                uiLater(function () use ($info) {
                                    $editor = FileSystem::getOpenedEditor($info['file']);
                                    if ($editor->isAutoClose()) {
                                        $editor->delete();
                                    }
                                });
                            }
                        }
                    });
                });
                $timer->start();

                $this->trigger('start', []);
                $this->mainForm->show();
            }
        );
    }

    /**
     * Запустить коллбэка в очереди потоков IDE.
     *
     * @param callable $callback
     * @param callable|null $after
     */
    public static function async(callable $callback, callable $after = null)
    {
        $ide = self::get();

        if ($ide->asyncThreadPool->isShutdown() || $ide->asyncThreadPool->isTerminated()) {
            return;
        }

        $ide->asyncThreadPool->execute(function () use ($callback, $after) {
            $result = $callback();

            if ($after) {
                $after($result);
            }
        });
    }

    public function isWindows()
    {
        return Str::contains($this->OS, 'win');
    }

    public function isLinux()
    {
        return Str::contains($this->OS, 'nix') || Str::contains($this->OS, 'nux') || Str::contains($this->OS, 'aix');
    }

    public function isMac()
    {
        return Str::contains($this->OS, 'mac');
    }

    /**
     * @return IdeLibrary
     */
    public function getLibrary()
    {
        return $this->library;
    }

    /**
     * Менеджер тулов/утилит.
     *
     * @return IdeToolManager
     */
    public function getToolManager()
    {
        return $this->toolManager;
    }

    /**
     * Утилита для локализации.
     *
     * @return L10n
     */
    public function getL10n()
    {
        if (!$this->l10n && $this->language) {
            $language = $this->languages[$this->language->getAltLang()];
            $this->l10n = $this->language->getL10n($language ? $language->getL10n() : $language);
        }

        return $this->l10n;
    }

    public function getLocalizer(): IdeLocalizer
    {
        return $this->localizer;
    }

    /**
     * Текущий язык.
     *
     * @return IdeLanguage
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Списки доступных языков IDE.
     *
     * @return IdeLanguage[]
     */
    public function getLanguages()
    {
        return $this->languages;
    }

    /**
     * @param \Exception|\Error $e
     * @param string $context
     */
    public function sendError($e, $context = 'global')
    {
        // noup
    }

    public function makeEnvironment()
    {
        $env = System::getEnv();

        if ($this->getJrePath()) $env['JAVA_HOME'] = $this->getJrePath();
        if (System::getEnv()["APP_HOME"] && $this->isLinux()) $env["APP_HOME"] = System::getEnv()["APP_HOME"];

        return $env;
    }
    /**
     * Вернуть путь к папке tools IDE.
     *
     * @return null|string
     */
    public function getToolPath()
    {
        $launcher = System::getProperty('Nearde.launcher');

        switch ($launcher) {
            case 'root':
                $path = $this->getOwnFile('tools/');
                break;
            default:
                $path = $this->getOwnFile('../tools/');
        }

        if ($this->isDevelopment() && !$path->exists()) {
            $path = $this->getOwnFile('../Nearde-tools/');
        }

        $file = $path && $path->exists() ? fs::abs($path) : null;

        //Logger::info("Detect tool path: '$file', mode = {$this->mode}, launcher = {$launcher}");

        return $file;
    }

    /**
     * Вернуть путь к apache ant тулу IDE.
     *
     * @return null|File
     */
    public function getApacheAntPath()
    {
        $antPath = new File($this->getToolPath(), '/apache-ant');

        if (!$antPath->exists()) {
            $antPath = System::getEnv()['ANT_HOME'];

            if ($antPath) {
                $antPath = File::of($antPath);
            }
        }

        return $antPath && $antPath->exists() ? $antPath->getCanonicalFile() : null;
    }

    /**
     * Вернуть путь к Gradle дистрибутиву.
     *
     * @deprecated не используется больше!
     * @return null|File
     */
    public function getGradlePath()
    {
        $gradlePath = new File($this->getToolPath(), '/gradle');

        if (!$gradlePath->exists()) {
            $gradlePath = System::getEnv()['GRADLE_HOME'];

            if ($gradlePath) {
                $gradlePath = File::of($gradlePath);
            }
        }

        return $gradlePath && $gradlePath->exists() ? $gradlePath->getCanonicalFile() : null;
    }

    /**
     * Вернуть путь к JRE среды (Java Runtime Environment).
     *
     * @return null|File
     */
    public function getJrePath()
    {
        $jrePath = Ide::getOwnFile("jre/");

        if (!$jrePath || !$jrePath->exists()) {
            $jrePath = System::getEnv()['JAVA_HOME'];

            if ($jrePath) {
                $jrePath = File::of($jrePath);
            }
        }

        return $jrePath && $jrePath->exists() ? $jrePath->getCanonicalFile() : null;
    }

    /**
     * Dev режим работы IDE?
     *
     * @return bool
     */
    public function isDevelopment()
    {
        return IdeSystem::isDevelopment();
    }

    /**
     * Prod режим работы IDE?
     *
     * @return bool
     */
    public function isProduction()
    {
        return Str::equalsIgnoreCase($this->mode, 'prod');
    }

    /**
     * Задать заголовок главной формы IDE.
     *
     * @param $value
     */
    public function setTitle($value)
    {
        $title = $this->getName() . ' ' . $this->getVersion();

        if ($value) {
            $title = $value . ' - ' . $title;
        }

        $this->getMainForm()->title = $title;
    }

    protected function readLanguages()
    {
        $this->languages = [];

        $directory = IdeSystem::getOwnFile('languages');

        if (self::isDevelopment() && !fs::isDir($directory)) {
            $directory = IdeSystem::getOwnFile('misc/languages');
        }

        fs::scan($directory, function ($path) {
            if (fs::isDir($path)) {
                $code = fs::name($path);

                Logger::info("Add ide language '$code', path = $path");

                $this->languages[$code] = new IdeLanguage($this->localizer, $code, $path);
            }
        }, 1);

        $ideLanguage = $this->getUserConfigValue('ide.language', System::getProperty('user.language'));

        if (!$this->languages[$ideLanguage]) {
            $ideLanguage = 'en';
        }

        $this->language = $this->languages[$ideLanguage];

        if ($this->language) {
            $this->localizer->language = $this->language->getCode();
        }

        $this->setUserConfigValue('ide.language', $ideLanguage);
    }

    /**
     * Вернуть именнованный конфиг из системной папки IDE.
     *
     * @param string $name
     * @return IdeConfiguration
     */
    public function getUserConfig($name)
    {
        $name = FileUtils::normalizeName($name);

        if ($config = $this->configurations[$name]) {
            return $config;
        }

        try {
            $config = new IdeConfiguration($this->getFile("$name.conf"));
        } catch (IOException $e) {
            // ...
        }

        return $this->configurations[$name] = $config;
    }

    /**
     * Вернуть значение глобального конфига, из ide.conf.
     *
     * @param string $key
     * @param mixed $def
     *
     * @return string
     */
    public function getUserConfigValue($key, $def = null)
    {
        return $this->getUserConfig('ide')->get($key, $def);
    }

    /**
     * Вернуть значение глобального конфига в виде массива, из ide.conf.
     *
     * @param string $key
     * @param mixed $def
     *
     * @return array
     */
    public function getUserConfigArrayValue($key, $def = [])
    {
        if ($this->getUserConfig('ide')->has($key)) {
            return $this->getUserConfig('ide')->getArray($key, $def);
        } else {
            return $def;
        }
    }

    /**
     * Задать глобальную настройку для IDE, запишет в конфиг ide.conf.
     *
     * @param $key
     * @param $value
     */
    public function setUserConfigValue($key, $value)
    {
        $this->getUserConfig('ide')->set($key, $value);
    }

    /**
     * Вернуть файл из папки, где находится сама IDE.
     *
     * @param string $path
     *
     * @return File
     */
    public static function getOwnFile($path)
    {
        $homePath = System::getProperty('Nearde.path', "./");
        if (System::getEnv()['APP_HOME']) $homePath = System::getEnv()['APP_HOME'];

        return File::of("$homePath/$path");
    }

    /**
     * Вернуть файл из системной папки IDE.
     *
     * @param string $path
     *
     * @return File
     */
    public function getFile($path)
    {
        return IdeSystem::getFile($path,
            $this->isSnapshotVersion() ? ".{$this->getVersionHash()}.SNAPSHOT" : ""
        );
    }

    /**
     * Вернуть файлы из директории, отвечающие формату.
     *
     * @param AbstractFormat|string $format
     * @param string $directory
     * @return \string[]
     * @throws IllegalArgumentException
     */
    public function getFilesOfFormat($format, $directory)
    {
        if (is_string($format)) {
            $format = $this->getRegisteredFormat($format);
        }

        if (!$format) {
            throw new IllegalArgumentException("Format is invalid");
        }

        $files = [];

        fs::scan($directory, function ($filename) use ($format, &$files) {
            if ($format->isValid($filename)) {
                $files[] = $filename;
            }
        });

        return $files;
    }

    /**
     * Вернуть текущий лог файл IDE.
     *
     * @return File
     */
    public function getLogFile()
    {
        $uuid = $this->getInstanceId();

        return $this->getFile("log/ide-$uuid.log");
    }

    /**
     * Очистить специализированную папку IDE от логов и кэша.
     */
    public function cleanup()
    {
        (new Thread(function() {
            Logger::info("Clean IDE files...");

            fs::scan($this->getFile("log/"), function ($logfile) {
                if (fs::time($logfile) < Time::millis() - 1000 * 60 * 60 * 3) { // 3 hours.
                    fs::delete($logfile);
                }
            });

            fs::scan($this->getFile("cache/"), function ($file) {
                if (fs::time($file) < Time::millis() - 1000 * 60 * 60 * 24 * 30) { // 30 days.
                    fs::delete($file);
                }
            });
        }))->start();
    }

    /**
     * Создать временный файл с специализированной папке IDE.
     *
     * @param string $suffix
     * @return File
     */
    public function createTempFile($suffix = '')
    {
        $tempDir = $this->getFile('tmp');

        if (!fs::isDir($tempDir)) {
            if (fs::exists($tempDir)) {
                fs::delete($tempDir);
            }
        }

        $tempDir->mkdirs();

        $file = File::createTemp(Str::random(5), Str::random(10) . $suffix, $tempDir);
        $file->deleteOnExit();
        return $file;
    }


    /**
     * @param string $suffix
     * @return File
     */
    public function createTempDirectory(string $suffix)
    {
        $tempDir = $this->getFile("tmp/$suffix");

        if (!fs::isDir($tempDir)) {
            if (fs::exists($tempDir)) {
                fs::delete($tempDir);
            }
        }

        $tempDir->mkdirs();

        return $tempDir;
    }

    /**
     * Вернуть список всех зарегистрированных шаблонов проекта.
     *
     * @return AbstractProjectTemplate[]
     */
    public function getProjectTemplates()
    {
        return $this->projectTemplates;
    }

    /**
     * @return AbstractProjectSupport[]
     */
    public function getProjectSupports(): array
    {
        return $this->projectSupports;
    }

    /**
     * Зарегистрировать шаблон проекта.
     *
     * @param AbstractProjectTemplate $template
     */
    public function registerProjectTemplate(AbstractProjectTemplate $template)
    {
        $class = get_class($template);

        if (isset($this->projectTemplates[$class])) {
            return;
        }

        $this->projectTemplates[$class] = $template;
    }

    /**
     * Отменить регистрацию одной команды по ее имени класса.
     *
     * @param $commandClass
     * @param bool $ignoreAlways
     */
    public function unregisterCommand($commandClass, $ignoreAlways = true)
    {
        /** @var MainForm $mainForm */
        $mainForm = $this->getMainForm();

        $data = $this->commands[$commandClass];

        if (!$data) {
            return;
        }

        /** @var AbstractCommand $command */
        $command = $data['command'];

        if (!$ignoreAlways && $command->isAlways()) {
            return;
        }

        if ($data['headUi']) {
            if (is_array($data['headUi'])) {
                foreach ($data['headUi'] as $ui) {
                    $mainForm->getHeadPane()->remove($ui);
                }
            } else {
                $mainForm->getHeadPane()->remove($data['headUi']);
            }
        }

        if ($data['headRightUi']) {
            if (is_array($data['headRightUi'])) {
                foreach ($data['headRightUi'] as $ui) {
                    $mainForm->getHeadRightPane()->remove($ui);
                }
            } else {
                $mainForm->getHeadRightPane()->remove($data['headRightUi']);
            }
        }

        if ($data['menuItem']) {
            /** @var UXMenu $menu */
            $menu = $mainForm->findSubMenu('menu' . Str::upperFirst($command->getCategory()));

            if ($menu instanceof UXMenu) {
                foreach ($data['menuItem'] as $el) {
                    $menu->items->remove($el);
                }
            }
        }

        unset($this->commands[$commandClass]);
    }

    /**
     * Отменить регистрацию всех команд.
     */
    public function unregisterCommands()
    {
        /** @var MainForm $mainForm */
        $mainForm = $this->getMainForm();

        if (!$mainForm) {
            return;
        }

        foreach ($this->commands as $code => $data) {
            $this->unregisterCommand($code, false);
        }
    }

    /**
     * Выполнить зарегистрированную команду по названию ее класса.
     *
     * @param $commandClass
     */
    public function executeCommand($commandClass)
    {
        Logger::info("Execute command - $commandClass");

        $command = $this->getRegisteredCommand($commandClass);

        if ($command) {
            $command->onExecute();
        } else {
            throw new \InvalidArgumentException("Unable to execute $commandClass command, it is not registered");
        }
    }

    /**
     * Вернуть команду по ее классу.
     *
     * @param string $commandClass class or uid
     * @return AbstractCommand|null
     */
    public function getRegisteredCommand($commandClass)
    {
        $command = $this->commands[$commandClass];

        if ($command) {
            /** @var AbstractCommand $command */
            $command = $command['command'];
            return $command;
        }

        return null;
    }

    /**
     * Зарегистрировать IDE команду.
     *
     * @param AbstractCommand $command
     * @param null $category
     */
    public function registerCommand(AbstractCommand $command, $category = null)
    {
        $this->unregisterCommand($command->getUniqueId());

        $data = [
            'command' => $command,
        ];

        $headUi = $command->makeUiForHead();

        if ($headUi) {
            $data['headUi'] = $headUi;

            $this->afterShow(function () use ($headUi) {
                /** @var MainForm $mainForm */
                $mainForm = $this->getMainForm();

                if (!is_array($headUi)) {
                    $headUi = [$headUi];
                }

                foreach ($headUi as $ui) {
                    if ($ui instanceof UXButton) {
                        $ui->maxHeight = 9999;
                    } else if ($ui instanceof UXSeparator) {
                        $ui->paddingLeft = 3;
                        $ui->paddingRight = 1;
                    }

                    $mainForm->getHeadPane()->children->insert($mainForm->getHeadPane()->children->count - 1, $ui);
                }
            });
        }

        $headRightUi = $command->makeUiForRightHead();

        if ($headRightUi) {
            $data['headRightUi'] = $headRightUi;

            $this->afterShow(function () use ($headRightUi) {
                /** @var MainForm $mainForm */
                $mainForm = $this->getMainForm();

                if (!is_array($headRightUi)) {
                    $headRightUi = [$headRightUi];
                }

                foreach ($headRightUi as $ui) {
                    if ($ui instanceof UXButton) {
                        $ui->maxHeight = 999;
                    } else if ($ui instanceof UXSeparator) {
                        $ui->paddingLeft = 3;
                        $ui->paddingRight = 1;
                    }

                    $mainForm->getHeadRightPane()->add($ui);
                }
            });
        }

        $category = $category ?: $command->getCategory();
        $menuItem = $command->makeMenuItem();

        if ($menuItem) {
            $data['menuItem'] = $menuItem;

            $this->afterShow(function () use ($menuItem, $command, &$data, $category) {
                /** @var MainForm $mainForm */
                $mainForm = $this->getMainForm();

                /** @var UXMenu $menu */
                $menu = $mainForm->findSubMenu('menu' . Str::upperFirst($category));

                if ($menu instanceof UXMenu) {
                    $items = [];

                    if ($command->withBeforeSeparator()) {
                        /** @var UXMenuItem $last */
                        $last = $menu->items->last();

                        if ($last && $last->isSeparator()) {
                            // do nothing...
                        } else {
                            $items[] = UXMenuItem::createSeparator();
                        }
                    }

                    $items[] = $menuItem;

                    if ($command->withAfterSeparator()) {
                        $items[] = UXMenuItem::createSeparator();
                    }

                    foreach ($items as $el) {
                        $menu->items->add($el);
                    }

                    $data['menuItem'] = $items;
                }


            });
        }

        $this->commands[$command->getUniqueId()] = $data;
    }

    /**
     * Вернуть изображение из ресурсов IDE /.data/img/
     *
     * @param string $path
     *
     * @param array $size
     * @param bool $cache
     * @return UXImageView
     */
    public static function getImage($path, array $size = null, $cache = true)
    {
        if ($path === null) {
            return null;
        }

        if ($path instanceof UXImage) {
            $image = $path;
        } elseif ($path instanceof UXImageView) {
            if ($size) {
                $image = $path->image;

                if ($image == null) {
                    return null;
                }
            } else {
                return $path;
            }
        } elseif ($path instanceof Stream) {
            $image = new UXImage($path);
        } elseif ($path instanceof LazyLoadingImage) {
            $image = $path->getImage();
        } else {
            $path = Ide::get()->getThemeManager()->getDefault()->iconAlias($path);

            if ($cache) {
                $image = Cache::getResourceImage("res://.data/img/" . $path);
            } else {
                $image = new UXImage('res://.data/img/' . $path);
            }
        }

        $result = new UXImageView();
        $result->image = $image;

        if ($size) {
            $result->size = $size;
            $result->preserveRatio = true;
        }

        return $result;
    }

    /**
     * Вернуть зарегистрированный формат по его классу.
     * В приоритете форматы, зарегистирированные в проекте, а уже затем - глобальные форматы.
     *
     * @param $class
     * @return AbstractFormat
     */
    public function getRegisteredFormat($class)
    {
        if ($project = $this->getOpenedProject()) {
            if ($format = $project->getRegisteredFormat($class)) {
               return $format;
            }
        }

        return $this->_getRegisteredFormat($class);
    }

    /**
     * Найти формат для редактирования файла/пути.
     *
     * @param $path
     *
     * @return AbstractFormat|null
     */
    public function getFormat($path)
    {
        if ($project = $this->getOpenedProject()) {
            /** @var AbstractFormat $format */
            foreach (arr::reverse($project->getRegisteredFormats()) as $format) {
                if ($format->isValid($path)) {
                    return $format;
                }
            }
        }

        /** @var AbstractFormat $format */
        foreach (arr::reverse($this->formats) as $format) {
            if ($format->isValid($path)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Вернуть текущий открытый проект.
     *
     * @return Project
     */
    public function getOpenedProject()
    {
        return $this->openedProject;
    }

    /**
     * Задать открытый проект.
     *
     * @param Project $openedProject
     */
    public function setOpenedProject($openedProject = null)
    {
        $this->openedProject = $openedProject;

        if ($openedProject) {
            $this->setTitle($openedProject->getName() . " - [" . $openedProject->getRootDir() . "]");
        } else {
            $this->setTitle(null);
        }
    }

    /**
     * Создать редактор для редактирования файла, формат по-умолчанию определяется автоматически,
     * с помощью ранее зарегистрированных редакторов.
     *
     * @param $path
     *
     * @param array $options
     * @param string $format
     * @return AbstractEditor
     */
    public function createEditor($path, array $options = [], $format = null)
    {
        $format = $format ? $this->getRegisteredFormat($format) : $this->getFormat($path);

        if ($format) {
            $editor = $format->createEditor($path, $options);

            if ($editor) {
                $editor->setFormat($format);
            }

            if ($project = Ide::project()) {
                if (!str::startsWith(FileUtils::hashName($path), FileUtils::hashName($project->getRootDir()))) {
                    $editor->setReadOnly(true);
                }

                $project->trigger('createEditor', $editor);
            }

            return $editor;
        }

        return null;
    }

    public function getAccountManager()
    {
        return null;
    }

    /**
     * @param string|AbstractProjectSupport $support
     * @throws IdeException
     */
    public function registerProjectSupport($support)
    {
        if (is_string($support)) {
            $support = new $support();
        }

        if (isset($this->projectSupports[reflect::typeOf($support)])) {
            return;
        }


        Logger::info("Register IDE project support " . reflect::typeOf($support));


        if (!($support instanceof AbstractProjectSupport)) {
            throw new IdeException("Unable to add project support " . reflect::typeOf($support) . ", is not correct type");
        }

        $this->projectSupports[reflect::typeOf($support)] = $support;
        $support->onRegisterInIDE();
    }

    /**
     * Зарегистрировать расширение IDE (по названию класса или его экземпляру).
     *
     * @param string|AbstractExtension $extension
     * @throws IdeException
     */
    public function registerExtension($extension)
    {
        if (is_string($extension)) {
            $extension = new $extension();
        }

        if (isset($this->extensions[reflect::typeOf($extension)])) {
            return;
        }

        Logger::info("Register IDE extension " . reflect::typeOf($extension));

        if (!($extension instanceof AbstractExtension)) {
            throw new IdeException("Unable to add extension " . reflect::typeOf($extension) . ", is not correct type");
        }

        $this->extensions[reflect::typeOf($extension)] = $extension;

        foreach ((array) $extension->getDependencies() as $class) {
            $dep = new $class();

            if ($dep instanceof AbstractBundle) {
                IdeSystem::getLoader()->addClassPath($dep->getVendorDirectory());
            } else {
                $this->registerExtension($extension);
            }
        }

        $extension->onRegister();
    }

    public function registerAll()
    {
        $this->cleanup();

        $extensions = $this->getInternalList('.nearde/extensions');

        foreach ($extensions as $extension) {
            $this->registerExtension($extension);
        }

        $this->library->update();

        /** @var AccurateTimer $inactiveTimer */
        $inactiveTimer = new AccurateTimer(3 * 60 * 1000, function () {
            $this->idle = true;
            Logger::info("IDE is sleeping, idle mode ...");
            $this->trigger('idleOn');
        });
        $inactiveTimer->start();

        $this->getMainForm()->addEventFilter('mouseMove', function () use (&$inactiveTimer) {
            if ($inactiveTimer) {
                $inactiveTimer->reset();
            }

            if ($this->idle) {
                Logger::info("IDE awake, idle mode = off ...");
                $this->trigger('idleOff');
            }

            $this->idle = false;
        });

        $ideConfig = $this->getUserConfig('ide');

        $defaultProjectDir = File::of(System::getProperty('user.home') . '/NeardeProjects');

        if (!fs::isDir($ideConfig->get('projectDirectory'))) {
            $ideConfig->set('projectDirectory', "$defaultProjectDir/");
        }

        if ($this->isSnapshotVersion()) {
            $ideConfig->set('projectDirectory', "$defaultProjectDir.{$this->getVersionHash()}.SNAPSHOT");
        }

        $this->afterShow(function () {
            $projectFile = $this->getUserConfigValue('lastProject');

            FileSystem::open('~welcome');

            if (!$this->disableOpenLastProject && $projectFile && File::of($projectFile)->exists()) {
                ProjectSystem::open($projectFile, false);
            }
        });
    }

    /**
     * Добавить коллбэка, выполняющийся после старта и показа IDE.
     * Если IDE уже была показана, коллбэк будет выполнен немедленно.
     *
     * @param callable $handle
     */
    public function afterShow(callable $handle)
    {
        if ($this->isLaunched()) {
            $handle();
        } else {
            $this->afterShow[] = $handle;
        }
    }

    /**
     * Находится ли IDE в спящем режиме (т.е. не используется).
     * @return boolean
     */
    public function isIdle()
    {
        return $this->idle;
    }

    /**
     * Вернуть главную форму.
     * @return MainForm
     */
    public function getMainForm()
    {
        return $this->mainForm;
    }


    /**
     * @return Ide
     */
    public static function get()
    {
        return static::$ide;
    }

    /**
     * Вернуть открытый проект.
     * @return Project
     */
    public static function project()
    {
        return self::get()->getOpenedProject();
    }

    /**
     * Показать toast сообщение на главной форме IDE.
     *
     * @param $text
     * @param int $timeout
     */
    public static function toast($text, $timeout = 0)
    {
        Ide::get()->getMainForm()->toast($text, $timeout);
    }

    /**
     * Завершить работу IDE.
     */
    public function shutdown()
    {
        $this->shutdown = true;

        $done = false;

        $shutdownTh = (new Thread(function () use (&$done) {
            sleep(40);

            while (!$done) {
                sleep(1);
            }

            Logger::warn("System halt 0\n");
            System::halt(0);
        }));

        $shutdownTh->setName("Nearde Shutdown");
        $shutdownTh->start();

        Logger::info("Start IDE shutdown ...");

        $this->trigger(__FUNCTION__);

        (new Thread(function () {
            Logger::info("Shutdown asyncThreadPool");
            $this->asyncThreadPool->shutdown();
        }))->start();

        foreach ($this->extensions as $extension) {
            try {
                Logger::info("Shutdown IDE extension " . get_class($extension) . ' ...');
                $extension->onIdeShutdown();
            } catch (\Exception $e) {
                Logger::exception("Unable to shutdown IDE extension " . get_class($extension), $e);
            }
        }

        $project = $this->getOpenedProject();

        $this->mainForm->hide();

        foreach ($this->configurations as $name => $config) {
            if ($config->isAutoSave()) {
                $config->saveFile();
            }
        }

        if ($project) {
            FileSystem::getSelectedEditor()->save();
            ProjectSystem::close(false);
        }

        $this->platform->onIdeShutdown();

        Logger::info("Finish IDE shutdown");

        try {
            Logger::shutdown();
            parent::shutdown();
        } catch (\Exception $e) {
            System::halt(0);
        }
        
        System::halt(0);
    }

    /**
     * @param $resourceName
     * @return array
     */
    public function getInternalList($resourceName)
    {
        static $cache;

        if ($result = $cache[$resourceName]) {
            return $result;
        }

        $resources = ResourceStream::getResources($resourceName);

        $result = [];

        if (!$resources) {
            Logger::warn("Internal list '$resourceName' is empty");
        }

        foreach ($resources as $resource) {
            $scanner = new Scanner($resource, 'UTF-8');

            while ($scanner->hasNextLine()) {
                $line = Str::trim($scanner->nextLine());

                if ($line && !str::startsWith($line, '#')) {
                    $result[] = $line;
                }
            }
        }

        return $cache[$resourceName] = $result;
    }

    /**
     * @param $argv
     * @return bool
     */
    protected function handleArgs($argv)
    {
        $this->trigger("handle-args", $argv);

        return false;
    }

    /**
     * Отключить открытие последнего редактируемого проекта.
     * Можно применять при старте IDE, чтобы отменить загрузку предыдущего проекта.
     */
    public function disableOpenLastProject()
    {
        $this->disableOpenLastProject = true;
    }

    /**
     * Сравнить версию с версией IDE.
     * @param $otherVersion
     * @return bool
     */
    public function isSameVersionIgnorePatch($otherVersion)
    {
        $version = IdeSystem::getVersionInfo($this->getVersion());
        $otherVersion = IdeSystem::getVersionInfo($otherVersion);

        $result = $otherVersion['type'] === $version['type'] && $otherVersion['major'] == $version['major']
            && $otherVersion['minor'] === $version['minor'];

        Logger::debug("isSameVersionIgnorePatch(): " . Json::encode($version) . " with " . Json::encode($otherVersion)
            . " is " . ($result ? 'true' : 'false'));

        return $result;
    }


    /**
     * @throws \php\lang\IllegalStateException
     * Restart IDE, запустить рестарт IDE, работает только в production режиме.
     */
    public function restart()
    {
        $this->startNew();

        if (Ide::project()) {
            Ide::get()->setUserConfigValue('lastProject', Ide::project()->getProjectFile());
        }

        Ide::toast("Restarting IDE ...");

        $this->shutdown();
    }

    /**
     * @param string $name
     * @param array $libPaths
     * @return null|File
     */
    public function findLibFile($name, array $libPaths = [])
    {
        /** @var File[] $libPaths */
        $libPaths[] = $this->getOwnFile('lib/');

        if ($this->isDevelopment()) {
            $ownFile = $this->getOwnFile('build/install/DevelNext/lib');
            $libPaths[] = $ownFile;
        }

        $regex = Regex::of('(\.[0-9]+|\-[0-9]+)');

        $name = $regex->with($name)->replace('');

        foreach ($libPaths as $libPath) {
            foreach ($libPath->findFiles() as $file) {
                $filename = $regex->with($file->getName())->replace('');

                if (str::endsWith($filename, '.jar') || str::endsWith($filename, '-SNAPSHOT.jar')) {
                    $filename = str::sub($filename, 0, Str::length($filename) - 4);

                    if (str::endsWith($filename, '-SNAPSHOT')) {
                        $filename = Str::sub($filename, 0, Str::length($filename) - 9);
                    }

                    if ($filename == $name) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return ThemeManager
     */
    public function getThemeManager(): ThemeManager
    {
        return $this->themeManager;
    }

    /**
     * Запустить новый экземпляр ide.
     * @param array $args
     * @throws \php\lang\IllegalStateException
     */
    public function startNew(array $args = [])
    {
        $jrePath = $this->getJrePath();
        $args = flow([$jrePath ? "$jrePath/bin/java" : "java", '-jar', 'NeardeLauncher.jar'])->append($args)->toArray();

        $process = new Process($args, Ide::getOwnFile(null), $this->makeEnvironment());
        $process->start();
    }

    /**
     * @return SettingsContainer
     */
    public function getSettingsContainer(): SettingsContainer
    {
        return $this->settingsContainer;
    }

    /**
     * @return AbstractExtension[]
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * @return AbstractPlatform
     */
    public function getPlatform(): AbstractPlatform
    {
        return $this->platform;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->platform->getIDEName();
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->platform->getIDEVersion();
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getIcon(): string
    {
        return $this->platform->getIDEIcon();
    }
}