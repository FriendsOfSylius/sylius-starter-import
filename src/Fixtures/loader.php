<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use function Castor\io;

function load_import_fixture_suite(string $projectSlug): void
{
    if (!project_has_generated_fixtures($projectSlug)) {
        throw new \RuntimeException(\sprintf(
            'No generated fixtures found for project "%s". Run sylius:import:fixtures:generate first.',
            $projectSlug,
        ));
    }

    write_import_suite_loader($projectSlug);
    ensure_docker_ready();

    $target = import_shop_target($projectSlug);

    io()->title(\sprintf('Loading import fixtures for %s', $projectSlug));
    import_log(\sprintf(
        $target['shared']
            ? 'Removing previously imported %2$s catalog from channel %1$s (the channel is kept), then loading suite import.'
            : 'Resetting channel %s if it already exists, then loading suite import.',
        $target['channel'],
        import_code_prefix($projectSlug),
    ));

    import_docker_compose_run(import_shop_reset_cli($projectSlug));
    import_docker_compose_run('php bin/console sylius:fixtures:load import -n');
    import_log('Fixture suite loaded successfully.');
}
