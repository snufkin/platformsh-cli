<?php
/**
 * @file
 * Override Symfony Console's ListCommand to customize the list's appearance.
 */

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\CustomTextDescriptor;
use Symfony\Component\Console\Command\ListCommand as ParentListCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends ParentListCommand
{

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('xml')) {
            $input->setOption('format', 'xml');
        }

        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor());
        $helper->describe($output, $this->getApplication(), array(
            'format' => $input->getOption('format'),
            'raw_text' => $input->getOption('raw'),
            'namespace' => $input->getArgument('namespace'),
          ));
    }

}
