<?php

declare(strict_types=1);

namespace Unit\Tasks;

use Castor\Attribute\AsTask;
use Castor\Sylius\Tasks\ImportTasks;
use PHPUnit\Framework\TestCase;

final class ImportTasksTest extends TestCase
{
    public function testRegistersImportTasks(): void
    {
        $tasks = new ImportTasks('app', '/tmp/app');
        $expected = [
            ['list', 'sylius:import'],
            ['delete', 'sylius:import'],
            ['build', 'sylius:import:ai'],
            ['generate', 'sylius:import:fixtures'],
            ['load', 'sylius:import:fixtures'],
        ];

        $index = 0;

        foreach ($tasks() as $task) {
            /** @var AsTask $descriptor */
            $descriptor = $task['task'];
            [$name, $namespace] = $expected[$index];
            static::assertSame($name, $descriptor->name);
            static::assertSame($namespace, $descriptor->namespace);
            static::assertArrayHasKey('function', $task);
            ++$index;
        }

        static::assertSame(\count($expected), $index);
    }
}
