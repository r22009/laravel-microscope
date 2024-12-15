<?php

namespace Imanghafoori\LaravelMicroscope\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\LaravelMicroscope\Foundations\PhpFileDescriptor;
use Imanghafoori\LaravelMicroscope\Traits\LogsErrors;
use Imanghafoori\TokenAnalyzer\ClassMethods;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

class CheckUnusedControllerMethods extends Command
{
    use LogsErrors;

    protected $signature = 'check:controller-methods';

    protected $description = 'Checks the validity of route definitions';

    public static $checkedRoutesNum = 0;

    public static $skippedRoutesNum = 0;

    public function getControllerClassAndMethodsFromFiles(): array
    {
        $controllerPath = app_path('Http/Controllers');
        $result = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerPath)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = 'App\\' . str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    Str::after($file->getPathname(), app_path() . DIRECTORY_SEPARATOR)
                );

                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);
                    $methods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                        ->whereNotIn('name', ['__construct'])
                        ->filter(fn ($method) => $method->class === $className)
                        ->pluck('name')
                        ->toArray();
                    foreach ($methods as $method) {
                        $result[] = [$className, $method, $file->getRealPath()];
                    }
                }
            }
        }

        return $result;
    }
    private function diff(array $a1, array $a2): array
    {
        $a1_flattened = array_map('json_encode', $a1);
        $a2_flattened = array_map('json_encode', $a2);
        $diff = array_diff($a1_flattened, $a2_flattened);

        // Convert back from JSON to arrays
        $result = array_map('json_decode', $diff);

        return array_values($result);
    }
    public function handle(ErrorPrinter $errorPrinter): int
    {
        $this->info('Checking unused controller methods...');
        event('microscope.start.command');
        $errorPrinter->printer = $this->output;
        app(Filesystem::class)->delete(app()->getCachedRoutesPath());
        $methodsFromRoutes = $this->getControllerClassAndMethodsFromRoutes();
        $methodsFromFiles = $this->getControllerClassAndMethodsFromFiles();
        $diffs = $this->diff($methodsFromFiles, $methodsFromRoutes);
        foreach ($diffs as $diff) {
            $file = PhpFileDescriptor::make($diff[2]);
            $tokens = $file->getTokens();
            $methods = ClassMethods::read($tokens)['methods'];
            $lineInfo = collect($methods)->filter(fn ($item) => $item['name'][1] === $diff[1])->first();
            if (!$lineInfo) {
                continue;
            }
            $msg2 = 'Unused method '. $diff[1];
            self::appendError('', $msg2, '', $diff[2], $lineInfo['name'][2]);
        }

        $this->getOutput()->writeln(
            $this->getStatisticsMsg()
        );

        $this->finishCommand($errorPrinter);
        $this->getOutput()->writeln(' - '.self::$checkedRoutesNum.' gate definitions were checked.');
        event('microscope.finished.checks', [$this]);

        return $errorPrinter->hasErrors() ? 1 : 0;
    }
    public static function appendError($path, $errorIt, $errorTxt, $absPath = null, $lineNumber = 0)
    {
        $p = ErrorPrinter::singleton();
        $p->simplePendError($path, $absPath, $lineNumber, 'controller', $errorIt, $errorTxt);
    }

    private function getStatisticsMsg()
    {
        return ' - '.self::$checkedRoutesNum.' controller methods were checked. ('.self::$skippedRoutesNum.' skipped)';
    }

    private function getControllerClassAndMethodsFromRoutes()
    {
        $routes = app(Router::class)->getRoutes()->getRoutes();
        $response = [];
        foreach ($routes as $route) {
            if (! is_string($ctrl = $route->getAction()['uses'])) {
                self::$skippedRoutesNum++;
                continue;
            }
            self::$checkedRoutesNum++;

            [$ctrlClass, $method] = Str::parseCallback($ctrl, '__invoke');
            $response[] = [$ctrlClass, $method, (new ReflectionClass($ctrlClass))->getFileName()];
        }

        return $response;
    }
}
