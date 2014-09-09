<?php
namespace Grav\Console\Gpm;

use Grav\Common\GPM\GPM;
use Grav\Common\GPM\Response;
use Grav\Console\ConsoleTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class InstallCommand extends Command {
    use ConsoleTrait;

    protected $data;
    protected $gpm;
    protected $destination;
    protected $file;

    protected function configure() {
        $this
            ->setName("install")
            ->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Force re-fetching the data from remote'
        )
            ->addOption(
            'all-yes',
            'y',
            InputOption::VALUE_NONE,
            'Assumes yes (or best approach) instead of prompting'
        )
            ->addOption(
            'destination',
            'd',
            InputOption::VALUE_OPTIONAL,
            'The destination where the package should be installed at. By default this would be where the grav instance has been launched from',
            GRAV_ROOT
        )
            ->addArgument(
            'package',
            InputArgument::IS_ARRAY|InputArgument::REQUIRED,
            'The package of which more informations are desired. Use the "index" command for a list of packages'
        )
            ->setDescription("Performs the installation of plugins and themes")
            ->setHelp('The <info>install</info> command allows to install plugins and themes');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->setupConsole($input, $output);

        $this->gpm         = new GPM($this->input->getOption('force'));
        $this->destination = realpath($this->input->getOption('destination'));

        $packages   = array_map('strtolower', $this->input->getArgument('package'));
        $this->data = $this->gpm->findPackages($packages);

        $this->isGravInstance($this->destination);

        $this->output->writeln('');

        if (!$this->data['total']) {
            $this->output->writeln("Nothing to install.");
            $this->output->writeln('');
            exit;
        }

        if (count($this->data['not_found'])) {
            $this->output->writeln("These packages were not found on Grav: <red>" . implode('</red>, <red>', $this->data['not_found']) . "</red>");
        }

        unset($this->data['not_found']);
        unset($this->data['total']);

        foreach ($this->data as $type => $data) {
            foreach ($data as $slug => $package) {
                $this->output->writeln("Preparing to install <cyan>" . $package->name . "</cyan> [v" . $package->version . "]");

                $this->output->write("  |- Downloading package...     0%");
                $this->file = $this->downloadPackage($package);

                $this->output->write("  |- Checking destination...  ");
                $checks = $this->checkDestination($package);

                if (!$checks) {
                    $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
                } else {
                    $this->output->write("  |- Installing package...  ");
                    $installation = $this->installPackage($package);
                    if (!$installation) {
                        $this->output->writeln("  '- <red>Installation failed or aborted.</red>");
                        $this->output->writeln('');
                    } else {
                        $this->output->writeln("  '- <green>Success!</green>  ");
                        $this->output->writeln('');
                    }
                }
            }

            $this->output->writeln('');
        }

        $this->output->writeln('');
        $this->rrmdir($this->destination . DS . 'tmp-gpm');
    }

    private function downloadPackage($package) {

        $tmp      = $this->destination . DS . 'tmp-gpm';
        $filename = $package->slug . basename($package->download);
        $output   = Response::get($package->download, [], [$this, 'progress']);

        $this->output->write("\x0D");
        $this->output->write("  |- Downloading package...   100%");

        $this->output->writeln('');

        if (!file_exists($tmp)) {
            @mkdir($tmp);
        }

        file_put_contents($tmp . DS . $filename, $output);

        return $tmp . DS . $filename;
    }

    private function checkDestination($package) {
        $destination    = $this->destination . DS . $package->install_path;
        $questionHelper = $this->getHelper('question');
        $skipPrompt     = $this->input->getOption('all-yes');

        if (is_dir($destination) && !is_link($destination)) {
            if (!$skipPrompt) {
                $this->output->write("\x0D");
                $this->output->writeln("  |- Checking destination...  <yellow>exists</yellow>");

                $question = new ConfirmationQuestion("  |  '- The package has been detected as installed already, do you want to overwrite it? [y|N] ", false);
                $answer   = $questionHelper->ask($this->input, $this->output, $question);

                if (!$answer) {
                    $this->output->writeln("  |     '- <red>You decided to not overwrite the already installed package.</red>");
                    return false;
                }
            }

            $this->rrmdir($destination);
            @mkdir($destination, 0777, true);
        }

        if (is_link($destination)) {
            $this->output->write("\x0D");
            $this->output->writeln("  |- Checking destination...  <yellow>symbolic link</yellow>");

            if ($skipPrompt) {
                $this->output->writeln("  |     '- <yellow>Skipped automatically.</yellow>");
                return false;
            }

            $question = new ConfirmationQuestion("  |  '- Destination has been detected as symlink, delete symbolic link first? [y|N] ", false);
            $answer   = $questionHelper->ask($this->input, $this->output, $question);

            if (!$answer) {
                $this->output->writeln("  |     '- <red>You decided to not delete the symlink automatically.</red>");
                return false;
            }

            @unlink($destination);
        }

        $this->output->write("\x0D");
        $this->output->writeln("  |- Checking destination...  <green>ok</green>");

        return true;
    }

    private function installPackage($package) {
        $destination = $this->destination . DS . $package->install_path;
        $zip         = new \ZipArchive;
        $openZip     = $zip->open($this->file);
        $tmp         = $this->destination . DS . 'tmp-gpm';

        if (!$openZip) {
            $this->output->write("\x0D");
            // extra white spaces to clear out the buffer properly
            $this->output->writeln("  |- Installing package...    <red>error</red>                             ");
            $this->output->writeln("  |  '- Unable to open the downloaded package: <yellow>" . $package->download . "</yellow>");

            return false;
        }

        $innerFolder = $zip->getNameIndex(0);

        $zip->extractTo($tmp);
        $zip->close();

        rename($tmp . DS . $innerFolder, $destination);

        $this->output->write("\x0D");
        // extra white spaces to clear out the buffer properly
        $this->output->writeln("  |- Installing package...    <green>ok</green>                             ");
        return true;
    }

    public function progress($progress) {
        $this->output->write("\x0D");
        $this->output->write("  |- Downloading package... " . str_pad($progress['percent'], 5, " ", STR_PAD_LEFT) . '%');
    }

    // Recursively Delete folder - DANGEROUS! USE WITH CARE!!!!
    private function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir") {$this->rrmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }

            reset($objects);
            rmdir($dir);
            return true;
        }
    }
}
