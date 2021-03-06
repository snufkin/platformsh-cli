<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCheckoutCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:checkout')
            ->setAliases(array('checkout'))
            ->setDescription('Check out an environment')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The ID of the environment to check out. For example: "sprint2"'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new \Exception('This can only be run from inside a project directory');
        }

        $branch = $input->getArgument('id');
        if (empty($branch) && $input->isInteractive()) {
            $environments = $this->getEnvironments($project);
            $currentEnvironment = $this->getCurrentEnvironment($project);
            if ($currentEnvironment) {
                $output->writeln("The current environment is <info>{$currentEnvironment['title']}</info>.");
            }
            $environmentList = array();
            foreach ($environments as $id => $environment) {
                if ($currentEnvironment && $id == $currentEnvironment['id']) {
                    continue;
                }
                $environmentList[$id] = $environment['title'];
            }
            if (!count($environmentList)) {
                $output->writeln("Use <info>platform branch</info> to create an environment.");
                return 1;
            }
            $chooseEnvironmentText = "Enter a number to check out another environment:";
            $helper = $this->getHelper('question');
            $machineName = $helper->choose($environmentList, $chooseEnvironmentText, $input, $output);
        }
        elseif (empty($branch)) {
            $output->writeln("<error>No branch specified.</error>");
            return 1;
        }
        else {
            $machineName = $this->sanitizeEnvironmentId($branch);
        }

        $projectRoot = $this->getProjectRoot();

        $gitHelper = $this->getHelper('git');
        $gitHelper->setOutput($output);
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/repository');

        // If the branch doesn't already exist locally, check whether it is a
        // Platform.sh environment.
        if (!$gitHelper->branchExists($machineName)) {
            if (!$this->getEnvironment($machineName, $project)) {
                $output->writeln("<error>Environment not found: $machineName</error>");
                return 1;
            }
            // Fetch from origin.
            // @todo don't assume that the Platform.sh remote is called 'origin'
            $gitHelper->execute(array('fetch', 'origin'));
        }

        // Check out the branch.
        return $gitHelper->checkOut($machineName) ? 0 : 1;
    }
}
