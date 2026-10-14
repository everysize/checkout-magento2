<?php

declare(strict_types=1);

namespace Everysize\Checkout\Console\Command;

use Everysize\Checkout\Model\Sync;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * everysize:sync — import and report back in one run; the command for the
 * cron job (every 5 minutes). everysize:order:import and
 * everysize:order:export run one half, e.g. for testing.
 */
class SyncCommand extends Command
{
    public const MODE_ALL = 'all';
    public const MODE_IMPORT = 'import';
    public const MODE_EXPORT = 'export';

    private const NAMES = [
        self::MODE_ALL => 'everysize:sync',
        self::MODE_IMPORT => 'everysize:order:import',
        self::MODE_EXPORT => 'everysize:order:export',
    ];

    private const DESCRIPTIONS = [
        self::MODE_ALL => 'Import new everysize orders and report shipping, cancellations and returns',
        self::MODE_IMPORT => 'Import new everysize orders and report their order numbers',
        self::MODE_EXPORT => 'Report shipping, cancellations and returns to everysize',
    ];

    private Sync $sync;
    private State $appState;
    private string $mode;

    public function __construct(Sync $sync, State $appState, string $mode = self::MODE_ALL, ?string $name = null)
    {
        $this->sync = $sync;
        $this->appState = $appState;
        $this->mode = $mode;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAMES[$this->mode]);
        $this->setDescription(self::DESCRIPTIONS[$this->mode]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // CLI runs without an area code; store config, locale and tax rules
        // need one. The crontab area matches how the scheduled run behaves.
        $result = $this->appState->emulateAreaCode(Area::AREA_CRONTAB, function (): array {
            return $this->sync->run($this->mode !== self::MODE_EXPORT, $this->mode !== self::MODE_IMPORT);
        });

        foreach ($result['messages'] as $message) {
            $output->writeln($message);
        }
        foreach ($result['errors'] as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        return $result['errors'] === [] ? 0 : 1;
    }
}
