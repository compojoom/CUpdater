<?php

require_once "phing/Task.php";

class listJPackageFilesTask extends Task
{

    public $file;

    public function setFile($str)
    {
        $this->file = $str;
    }

    public function setSourceDir($dir)
    {
        $this->sourceDir = $dir;
    }

    /**
     * The init method: Do init steps.
     */
    public function init()
    {
        // nothing to do here
    }

    /**
     * The main entry point method.
     */
    public function main()
    {
        $content = file_get_contents($this->file);

        $content = preg_replace_callback('/##PACKAGEFILESPLUGIN##/', 'self::findPluginPackageFiles', $content);

        if(preg_match('/##ADMINLANGUAGEFILES##/', $content)) {
            $content = preg_replace('/##ADMINLANGUAGEFILES##/',
                call_user_func('self::adminLanaguageFiles', true), $content);
        }

        if(preg_match('/##ADMINLANGUAGEFILES##/', $content)) {
            $content = preg_replace('/##FRONTENDLANGUAGEFILES##/',
                call_user_func('self::adminLanaguageFiles', false), $content);
        }



        file_put_contents($this->file, $content);
    }

    public function adminLanaguageFiles($admin = false) {

        $languageFolder = $this->sourceDir . '/language';
        if($admin) {
            $languageFolder = $this->sourceDir . '/administrator/language';
        }
        $list = array();
        $dir = new DirectoryIterator($languageFolder);

        foreach ($dir as $element) {
            if (!$element->isDot()) {
                if ($element->isDir()) {
                    $langDir = new DirectoryIterator($element->getPath().'/'.$element->getFileName());

                    foreach($langDir as $langElement) {
                        if (!$langElement->isDot()) {
                            if($langElement->isFile()) {
                                $list[] = '<language tag="'.$element->getFileName().'">'.$element->getFileName().'/'.$langElement->getFileName().'</language>';
                            }
                        }
                    }
                }
            }
        }

        return implode("\n", $list);
    }

    public function findPluginPackageFiles()
    {
        $list = array();
        $dir = new DirectoryIterator($this->sourceDir);
        foreach ($dir as $element) {
            if (!$element->isDot()) {
                if ($element->isDir()) {
                    $skip = false;
                    if ($element->getFileName() == 'administrator') {
                        /**
                         * we need to handle the language folder in the plugin
                         * differently. If the administrator folder contains
                         * just the language folder we don't need to list it.
                         * Otherwise when the user installs the plugin he will have
                         * administrator/language in his plugi folder which is lame...
                         */
                        $adminDir = new DirectoryIterator($this->sourceDir . '/administrator');
                        $i = 0;
                        $language = false;
                        foreach ($adminDir as $adminElement) {
                            if ($adminElement->isDir() && !$adminElement->isDot()) {
                                if ($adminElement->getFileName() == 'language') {
                                    $language = true;
                                }
                                $i++;
                            }
                        }
                        /**
                         * so we have just one folder and it is
                         * the language one???
                         */
                        if ($i == 1 && $language == true) {
                            $skip = true;
                        }
                    }

                    if (!$skip) {
                        $list[] = '<folder>' . $element->getFileName() . '</folder>';
                    }
                }
                if ($element->isFile()) {
                    $packageMainFile = basename($this->file, '.xml');
                    if ($element->getFileName() == $packageMainFile . '.php') {
                        $list[] = '<file plugin="' . $packageMainFile . '">' . $element->getFilename() . '</file>';
                    } elseif ($element->getFileName() != basename($this->file)) {
                        $list[] = '<file>' . $element->getFileName() . '</file>';
                    }
                }
            }
        }

        return implode("\n", $list);
    }


}