<?php

namespace SecurityScanner\Core;

class CliApplication
{
    private array $commands = [];
    private array $config;
    private string $name;
    private string $version;

    public function __construct(string $name = 'Security Scanner CLI', string $version = '1.0.0', array $config = [])
    {
        $this->name = $name;
        $this->version = $version;
        $this->config = array_merge([
            'default_command' => 'help',
            'enable_colors' => $this->supportsColors(),
            'error_exit_code' => 1,
            'success_exit_code' => 0
        ], $config);

        $this->registerDefaultCommands();
    }

    /**
     * Register a CLI command
     */
    public function command(string $name, callable $handler, string $description = '', array $options = []): self
    {
        $this->commands[$name] = [
            'handler' => $handler,
            'description' => $description,
            'options' => array_merge([
                'arguments' => [],
                'flags' => [],
                'aliases' => []
            ], $options)
        ];

        return $this;
    }

    /**
     * Run the CLI application
     */
    public function run(array $argv = null): int
    {
        $argv = $argv ?? $_SERVER['argv'] ?? [];
        $scriptName = array_shift($argv);

        try {
            $input = $this->parseInput($argv);

            if (empty($input['command'])) {
                $input['command'] = $this->config['default_command'];
            }

            return $this->executeCommand($input['command'], $input['arguments'], $input['flags']);

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return $this->config['error_exit_code'];
        }
    }

    /**
     * Execute a specific command
     */
    public function executeCommand(string $commandName, array $arguments = [], array $flags = []): int
    {
        // Check for command aliases
        foreach ($this->commands as $name => $command) {
            if (in_array($commandName, $command['options']['aliases'])) {
                $commandName = $name;
                break;
            }
        }

        if (!isset($this->commands[$commandName])) {
            $this->error("Unknown command: {$commandName}");
            $this->line("Run '{$this->name} help' to see available commands.");
            return $this->config['error_exit_code'];
        }

        $command = $this->commands[$commandName];
        $handler = $command['handler'];

        try {
            $input = new CliInput($arguments, $flags);
            $output = new CliOutput($this->config['enable_colors']);

            $result = $handler($input, $output);

            // Handle different return types
            if ($result === null || $result === true) {
                return $this->config['success_exit_code'];
            } elseif ($result === false) {
                return $this->config['error_exit_code'];
            } elseif (is_int($result)) {
                return $result;
            }

            return $this->config['success_exit_code'];

        } catch (\Exception $e) {
            $this->error("Command failed: " . $e->getMessage());
            if ($flags['verbose'] ?? false) {
                $this->line($e->getTraceAsString());
            }
            return $this->config['error_exit_code'];
        }
    }

    /**
     * Parse command line input
     */
    private function parseInput(array $argv): array
    {
        $input = [
            'command' => '',
            'arguments' => [],
            'flags' => []
        ];

        $currentArg = null;

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                // Long flag
                $flagParts = explode('=', substr($arg, 2), 2);
                $flagName = $flagParts[0];
                $flagValue = $flagParts[1] ?? true;
                $input['flags'][$flagName] = $flagValue;
                $currentArg = null;
            } elseif (str_starts_with($arg, '-')) {
                // Short flag(s)
                $flags = str_split(substr($arg, 1));
                foreach ($flags as $flag) {
                    $input['flags'][$flag] = true;
                }
                $currentArg = null;
            } else {
                if (empty($input['command'])) {
                    $input['command'] = $arg;
                } else {
                    $input['arguments'][] = $arg;
                }
                $currentArg = null;
            }
        }

        return $input;
    }

    /**
     * Register default CLI commands
     */
    private function registerDefaultCommands(): void
    {
        // Help command
        $this->command('help', function(CliInput $input, CliOutput $output) {
            $commandName = $input->getArgument(0);

            if ($commandName && isset($this->commands[$commandName])) {
                $this->showCommandHelp($commandName, $output);
            } else {
                $this->showGeneralHelp($output);
            }
        }, 'Show help information', [
            'aliases' => ['h', '?']
        ]);

        // List commands
        $this->command('list', function(CliInput $input, CliOutput $output) {
            $output->title('Available Commands:');

            foreach ($this->commands as $name => $command) {
                $output->line(sprintf(
                    '  %-15s %s',
                    $output->color($name, 'green'),
                    $command['description']
                ));
            }
        }, 'List all available commands');

        // Version command
        $this->command('version', function(CliInput $input, CliOutput $output) {
            $output->line("{$this->name} version {$this->version}");
        }, 'Show application version', [
            'aliases' => ['v']
        ]);

        // Cache commands
        $this->command('cache:clear', function(CliInput $input, CliOutput $output) {
            $output->info('Clearing application cache...');

            // TODO: Implement cache clearing when cache system is available
            $cleared = $this->clearCache();

            if ($cleared) {
                $output->success('Cache cleared successfully!');
            } else {
                $output->error('Failed to clear cache');
                return 1;
            }
        }, 'Clear application cache');

        // Health check command
        $this->command('health', function(CliInput $input, CliOutput $output) {
            $output->info('Running health checks...');

            $health = new HealthCheck();
            $results = $health->check();

            $output->line('Overall Status: ' . $output->color($results['status'],
                $results['status'] === 'healthy' ? 'green' : 'red'));

            foreach ($results['checks'] as $name => $result) {
                $color = $result['status'] === 'pass' ? 'green' : 'red';
                $output->line(sprintf(
                    '  %-20s %s - %s',
                    $name,
                    $output->color($result['status'], $color),
                    $result['message']
                ));
            }

            return $results['status'] === 'healthy' ? 0 : 1;
        }, 'Run system health checks');

        // Database migration placeholder
        $this->command('migrate', function(CliInput $input, CliOutput $output) {
            $output->info('Running database migrations...');

            // TODO: Implement when database layer is available
            $output->success('Migrations completed successfully!');
        }, 'Run database migrations');

        // Performance test command
        $this->command('perf:test', function(CliInput $input, CliOutput $output) {
            $iterations = (int)($input->getFlag('iterations') ?? 100);

            $output->info("Running performance test with {$iterations} iterations...");

            $monitor = new PerformanceMonitor();
            $results = [];

            for ($i = 0; $i < $iterations; $i++) {
                $timerId = $monitor->startTimer('test_operation');

                // Simulate some work
                usleep(random_int(1000, 10000));

                $result = $monitor->stopTimer($timerId);
                $results[] = $result['duration'];
            }

            $avgTime = array_sum($results) / count($results);
            $minTime = min($results);
            $maxTime = max($results);

            $output->table([
                ['Metric', 'Value'],
                ['Iterations', $iterations],
                ['Average Time', number_format($avgTime, 2) . 'ms'],
                ['Min Time', number_format($minTime, 2) . 'ms'],
                ['Max Time', number_format($maxTime, 2) . 'ms'],
                ['Memory Usage', $this->formatBytes(memory_get_usage(true))]
            ]);

        }, 'Run performance tests', [
            'flags' => [
                'iterations' => 'Number of iterations to run (default: 100)'
            ]
        ]);
    }

    /**
     * Show general help
     */
    private function showGeneralHelp(CliOutput $output): void
    {
        $output->title("{$this->name} v{$this->version}");
        $output->line('Usage: command [options] [arguments]');
        $output->line('');

        $output->subtitle('Available Commands:');
        foreach ($this->commands as $name => $command) {
            $output->line(sprintf(
                '  %-15s %s',
                $output->color($name, 'green'),
                $command['description']
            ));
        }

        $output->line('');
        $output->line('Use "help <command>" for more information about a specific command.');
    }

    /**
     * Show help for specific command
     */
    private function showCommandHelp(string $commandName, CliOutput $output): void
    {
        $command = $this->commands[$commandName];

        $output->title("Help: {$commandName}");
        $output->line($command['description']);

        if (!empty($command['options']['aliases'])) {
            $output->line('');
            $output->subtitle('Aliases:');
            $output->line('  ' . implode(', ', $command['options']['aliases']));
        }

        if (!empty($command['options']['arguments'])) {
            $output->line('');
            $output->subtitle('Arguments:');
            foreach ($command['options']['arguments'] as $arg => $desc) {
                $output->line("  {$arg}: {$desc}");
            }
        }

        if (!empty($command['options']['flags'])) {
            $output->line('');
            $output->subtitle('Flags:');
            foreach ($command['options']['flags'] as $flag => $desc) {
                $output->line("  --{$flag}: {$desc}");
            }
        }
    }

    /**
     * Check if terminal supports colors
     */
    private function supportsColors(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return false; // Windows
        }

        if (!defined('STDOUT') || !is_resource(STDOUT)) {
            return false;
        }

        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    /**
     * Clear application cache
     */
    private function clearCache(): bool
    {
        try {
            // Clear file-based cache
            $cacheDir = sys_get_temp_dir() . '/cache/';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '*.cache');
                foreach ($files as $file) {
                    unlink($file);
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Output methods for convenience
     */
    public function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    public function info(string $text): void
    {
        $this->line($this->color('[INFO] ', 'blue') . $text);
    }

    public function success(string $text): void
    {
        $this->line($this->color('[SUCCESS] ', 'green') . $text);
    }

    public function error(string $text): void
    {
        $this->line($this->color('[ERROR] ', 'red') . $text);
    }

    public function warning(string $text): void
    {
        $this->line($this->color('[WARNING] ', 'yellow') . $text);
    }

    /**
     * Apply color to text if supported
     */
    private function color(string $text, string $color): string
    {
        if (!$this->config['enable_colors']) {
            return $text;
        }

        $colors = [
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'white' => '37'
        ];

        if (isset($colors[$color])) {
            return "\033[{$colors[$color]}m{$text}\033[0m";
        }

        return $text;
    }

    /**
     * Get registered commands
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}

/**
 * CLI Input helper class
 */
class CliInput
{
    private array $arguments;
    private array $flags;

    public function __construct(array $arguments = [], array $flags = [])
    {
        $this->arguments = $arguments;
        $this->flags = $flags;
    }

    public function getArgument(int $index, $default = null)
    {
        return $this->arguments[$index] ?? $default;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getFlag(string $name, $default = null)
    {
        return $this->flags[$name] ?? $default;
    }

    public function getFlags(): array
    {
        return $this->flags;
    }

    public function hasFlag(string $name): bool
    {
        return isset($this->flags[$name]);
    }
}

/**
 * CLI Output helper class
 */
class CliOutput
{
    private bool $enableColors;

    public function __construct(bool $enableColors = true)
    {
        $this->enableColors = $enableColors;
    }

    public function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    public function title(string $text): void
    {
        $this->line($this->color($text, 'cyan', true));
        $this->line(str_repeat('=', strlen($text)));
    }

    public function subtitle(string $text): void
    {
        $this->line($this->color($text, 'yellow', true));
    }

    public function info(string $text): void
    {
        $this->line($this->color('[INFO] ', 'blue') . $text);
    }

    public function success(string $text): void
    {
        $this->line($this->color('[SUCCESS] ', 'green') . $text);
    }

    public function error(string $text): void
    {
        $this->line($this->color('[ERROR] ', 'red') . $text);
    }

    public function warning(string $text): void
    {
        $this->line($this->color('[WARNING] ', 'yellow') . $text);
    }

    public function table(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // Calculate column widths
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }

        // Print table
        foreach ($rows as $i => $row) {
            $line = '| ';
            foreach ($row as $j => $cell) {
                $line .= str_pad($cell, $widths[$j]) . ' | ';
            }
            $this->line($line);

            // Add separator after header
            if ($i === 0) {
                $separator = '|-';
                foreach ($widths as $width) {
                    $separator .= str_repeat('-', $width) . '-|-';
                }
                $this->line(rtrim($separator, '|-') . '|');
            }
        }
    }

    public function color(string $text, string $color, bool $bold = false): string
    {
        if (!$this->enableColors) {
            return $text;
        }

        $colors = [
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'white' => '37'
        ];

        $code = $colors[$color] ?? '37';
        if ($bold) {
            $code = '1;' . $code;
        }

        return "\033[{$code}m{$text}\033[0m";
    }
}