<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FilesystemHelper extends Helper {

    protected $relative = false;
    protected $copyIfSymlinkUnavailable = true;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    public function getName()
    {
        return 'fs';
    }

    public function __construct(ShellHelperInterface $shellHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
    }

    /**
     * Set whether to use relative links.
     *
     * @param bool $relative
     */
    public function setRelativeLinks($relative)
    {
        // This is not possible on Windows.
        if (strpos(PHP_OS, 'WIN') !== false) {
            $relative = false;
        }
        $this->relative = $relative;
    }

    /**
     * Delete a directory and all of its files.
     *
     * @param string $directory A path to a directory.
     *
     * @return bool
     */
    public function rmdir($directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Not a directory: $directory");
        }
        return $this->remove($directory);
    }

    /**
     * Delete a file or directory.
     *
     * @param string $filename
     *
     * @return bool
     */
    public function remove($filename)
    {
        $fs = new Filesystem();
        try {
            $fs->remove($filename);
        }
        catch (IOException $e) {
            return false;
        }
        return true;
    }

    /**
     * @return string The absolute path to the user's home directory.
     */
    public function getHomeDirectory()
    {
        $home = getenv('HOME');
        if (empty($home)) {
            // Windows compatibility.
            if ($userProfile = getenv('USERPROFILE')) {
                $home = $userProfile;
            }
            elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
                $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            }
        }

        return $home;
    }

    /**
     * Copy all files and folders between directories.
     *
     * @param string $source
     * @param string $destination
     */
    public function copy($source, $destination)
    {
        if (!is_dir($source)) {
            throw new \InvalidArgumentException("Not a directory: $source");
        }
        if (!is_dir($destination)) {
            mkdir($destination);
        }
        $fs = new Filesystem();

        $skip = array('.', '..', '.git');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                if (is_dir($source . '/' . $file)) {
                    $this->copy($source . '/' . $file, $destination . '/' . $file);
                } else {
                    $fs->copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Create a symbolic link to a file or directory.
     *
     * @param $target
     * @param $link
     */
    public function symLink($target, $link)
    {
        $fs = new Filesystem();
        if (file_exists($link)) {
            $fs->remove($link);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, $link);
        }
        $fs->symlink($target, $link, $this->copyIfSymlinkUnavailable);
    }

    /**
     * Symlink all files and folders between two directories.
     *
     * @param string $source
     * @param string $destination
     * @param bool $skipExisting
     * @param string[] $blacklist
     *
     * @throws \Exception
     */
    public function symlinkAll($source, $destination, $skipExisting = true, $blacklist = array())
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array('.', '..', '.git');

        // Go through the blacklist, adding files to $skip.
        foreach ($blacklist as $pattern) {
            $matched = glob($source . '/' . $pattern, GLOB_NOSORT);
            if ($matched) {
                foreach ($matched as $filename) {
                    $relative = str_replace($source . '/', '', $filename);
                    $skip[$relative] = $relative;
                }
            }
        }

        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                $sourceFile = $source . '/' . $file;
                $linkFile = $destination . '/' . $file;

                if ($this->relative) {
                    $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                }

                if (file_exists($linkFile)) {
                    if (is_link($linkFile)) {
                        unlink($linkFile);
                    }
                    elseif ($skipExisting) {
                        continue;
                    }
                    else {
                        throw new \Exception('File exists: ' . $linkFile);
                    }
                }

                if (!function_exists('symlink') && $this->copyIfSymlinkUnavailable) {
                    copy($sourceFile, $linkFile);
                    continue;
                }

                symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make relative path between a symlink and a target.
     *
     * @param string $endPath Path of the file we are linking to.
     * @param string $startPath Path to the symlink that doesn't exist yet.
     *
     * @return string Relative path to the target.
     */
    protected function makePathRelative($endPath, $startPath)
    {
        $startPath = substr($startPath, 0, strrpos($startPath, DIRECTORY_SEPARATOR));
        $fs = new Filesystem();
        $result = rtrim($fs->makePathRelative($endPath, $startPath), DIRECTORY_SEPARATOR);
        return $result;
    }

    /**
     * Create a gzipped tar archive of a directory's contents.
     *
     * @param string $dir
     * @param string $destination
     */
    public function archiveDir($dir, $destination)
    {
        $tar = $this->getTarExecutable();
        $this->shellHelper->execute(array($tar, '-czp', '-C' . $dir, '-f' . $destination, '.'), null, true);
    }

    /**
     * Extract a gzipped tar archive into the specified destination directory.
     *
     * @param string $archive
     * @param string $destination
     */
    public function extractArchive($archive, $destination)
    {
        if (!file_exists($archive)) {
            throw new \InvalidArgumentException("Archive not found: $archive");
        }
        if (!is_writable(dirname($destination))) {
            throw new \InvalidArgumentException("Destination not writable: $destination");
        }
        $tar = $this->getTarExecutable();
        if (!file_exists($destination)) {
            mkdir($destination);
        }
        $this->shellHelper->execute(array($tar, '-xzp', '-C' . $destination, '-f' . $archive), null, true);
    }

    /**
     * @return string
     */
    protected function getTarExecutable()
    {
        $candidates = array('tar', 'tar.exe', 'bsdtar.exe');
        foreach ($candidates as $command) {
            if ($this->shellHelper->commandExists($command)) {
                return $command;
            }
        }
        throw new \RuntimeException("Tar command not found");
    }

}
