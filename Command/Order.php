<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Command;

use PlaceholderTech\Klar\Queue\OrderPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PlaceholderTech\Klar\Model\Api;

class Order extends Command
{
    const IDS_PARAM = 'ids';
    const FROM_DATE_PARAM = 'from-date';
    const TO_DATE_PARAM = 'to-date';
    const DEBUG_OPTION = 'debug';
    const DEBUG_OPTION_SHORT = 'd';

    private Api $api;
    private OrderPublisher $orderPublisher;

    /**
     * @param Api $api
     * @param OrderPublisher $orderPublisher
     * @param string|null $name
     */
    public function __construct(
        Api $api,
        OrderPublisher $orderPublisher,
        string $name = null
    ) {
        parent::__construct($name);
        $this->api = $api;
        $this->orderPublisher = $orderPublisher;
    }

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->setName('klar:order');
        $this->setDescription('Send specified orders to Klar');
        $this->setDefinition([
            new InputArgument(
                self::IDS_PARAM,
                InputArgument::REQUIRED,
                'Order IDs separated by comma, or "all" to send whole history'
            ),
            new InputArgument(
                self::FROM_DATE_PARAM,
                InputArgument::OPTIONAL,
                'From date to start "all" synchronization in format YYYY-MM-DD'
            ),
            new InputArgument(
                self::TO_DATE_PARAM,
                InputArgument::OPTIONAL,
                'To date to limit "all" synchronization in format YYYY-MM-DD'
            ),
            new InputOption(
                self::DEBUG_OPTION,
                self::DEBUG_OPTION_SHORT,
                InputOption::VALUE_NONE,
                'Debug mode - dumps the json data without sending it to API'
            ),
        ]);

        parent::configure();
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = false;
        $fromDate = null;
        $toDate = null;

        $idsInput = trim($input->getArgument(self::IDS_PARAM), ' ');
        $fromInput = $input->getArgument(self::FROM_DATE_PARAM);
        $toInput = $input->getArgument(self::TO_DATE_PARAM);
        $debug = $input->getOption(self::DEBUG_OPTION);
        if ($fromInput) {
            $fromDate = \DateTime::createFromFormat('Y-m-d', trim($fromInput, ' '));
        }
        if ($toInput) {
            $toDate = \DateTime::createFromFormat('Y-m-d', trim($toInput, ' '));
        }
        if ($idsInput == 'all') {
            $this->orderPublisher->publish($this->orderPublisher->getAllIds($fromDate, $toDate));
            $output->writeln('<info>Orders scheduled into queue successfully.</info>');
            return self::SUCCESS;
        }

        try {
            $ids = array_map('intval', explode(',', $idsInput));

            if ($ids) {
                if ($debug) {
                    var_dump($this->api->getJsonDataForOrders($ids));
                    return self::SUCCESS;
                }

                $result = $this->api->send($ids);
            }

            if ($result == count($ids)) {
                $output->writeln('<info>Orders sent successfully.</info>');
                return self::SUCCESS;
            } elseif ($result > 0) {
                $failed = count($ids) - $result;
                $output->writeln("<error>{$result} orders successfully sent. {$failed} order failed.</error>");
                return self::FAILURE;
            } else {
                $output->writeln('<error>Sending orders failed. Please check logs for more information.</error>');
                return self::FAILURE;
            }
        } catch (\Exception $exception) {
            $output->writeln('<error>Operation failed. Please check input parameters.</error>');
            return self::FAILURE;
        }
    }
}
