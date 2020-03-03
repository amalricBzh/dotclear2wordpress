<?php

// Settings : Change and customize this according to your future WP blog
$settings = [
    'author' => [
        'login' => 'AmalricBzh',
        'email' => 'xxx@xxx.xxx',
        'display_name' => 'AmalricBzh',
        'first_name' => 'Amalric',
        'last_name' => 'Bzh'
    ],
    'blog' => [
        'url' => 'http://blog.amalricbzh.org',
        'comment_status' => 'open',
        'ping_status' => 'open',
        'image_base_path' => 'wp-content/uploads/sites/2',
    ],
    'media' => [
        'source_dir' => 'tmp/import_dc',
        'target_dir' => 'tmp/uploads',
    ],
];

set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext) {
    // error was suppressed with the @-operator
    if (0 === error_reporting()) {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$convertor = new Dotclear2Wordpress($settings);
return $convertor->run();

class Dotclear2Wordpress
{
    private $projectName = 'Dotclear2Wordpress';
    private $version = '0.0.1';
    private $defaultOutputFilename = 'wordpress.xml';
    private $config = [];
    private $maxTermid = 1;
    private $dotclear = [];
    private $medias = [];


    public function __construct($config)
    {
        $this->config = $config;
    }

    public function run()
    {
        $this->init();
        $options = $this->getOptions();
        $dotclear = $this->readInput($options['input']);

        $dotclear = $this->dotclearConvertMeta($dotclear);
        $dotclear = $this->dotclearConvertCategories($dotclear);
        $this->dotclear = $dotclear;

        $this->printDotClearStats($dotclear);

        $xml = $this->generateXml($dotclear);

        // maintenant quelque chose de pas très propre: les images ont été copiées dans la
        // nouvelle arborescence, mais les urls ne sont aps à jour dans les posts...
        // De plus, les images retaillées par DC ne font pas partie de l'export.
        // On va donc chercher toutes ces images dans le xml (/public.../), puis si l'image
        // n'existe pas (retaillée) essayer de la créer, et remplacer enfin le chemin dans
        // xml. Bouh c'est un peu crado tout ça, mais ça marche et c'est pour du one-shot.
        $imagesInPosts = $this->searchImgInPosts($xml);
        $xml = $this->createResizedImages($imagesInPosts, $xml);
        $xml = $this->replaceMediaPath($xml);


        $result = $this->writeOutput($options['output'], $xml);

        return $result;

    }

    // Copie tous les medias dans un nouveau répertoire Upload.
    private function convertMedia()
    {
        $sourceDir = $this->config['media']['source_dir'];
        if (!file_exists($sourceDir)) {
            return [];
        }

        echo "    Copying medias: ";

        $targetDir = $this->config['media']['target_dir'];
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $filesDir = $sourceDir . DIRECTORY_SEPARATOR;

        /** @var SplFileInfo[] $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $movedFiles = [];
        // On copie tous les fichiers dans upload/2020/02/
        foreach ($files as $name => $file) {
            if (!$file->isFile()) {
                continue;
            }
            $fullFilename = trim(str_replace($sourceDir, '', $file->getPathname()), '\\');
            $newFilename = str_replace(['/', '\\'], '_', $fullFilename);
            $newPath = gmdate('Y/m', $file->getCTime());
            $currentFile = [
                'original_pathname' => $fullFilename,
                'new_pathname' => $newPath . '/' . $newFilename,
            ];

            if (!file_exists($targetDir . DIRECTORY_SEPARATOR . $newPath)) {
                mkdir($targetDir . DIRECTORY_SEPARATOR . $newPath, 0777, true);
            }

            copy(
                $sourceDir . DIRECTORY_SEPARATOR . $fullFilename,
                $targetDir . DIRECTORY_SEPARATOR . $newPath . DIRECTORY_SEPARATOR . $newFilename
            );

            $movedFiles[$currentFile['original_pathname']] = $currentFile;
        }
        echo count($movedFiles) . " files copied.\n";

        return $movedFiles;
    }

    public function searchImgInPosts($xml)
    {
        echo "    Searching images in posts: " ;
        // Pour chaque image nécessaire trouvée dans la sortie xml
        $matches = [];
        preg_match_all('^src=\\\"/public/([.A-Za-z0-9_/-]*)\\\"^', $xml, $matches);
        $files = array_unique($matches[1]);
        preg_match_all('^src=\\\"' . $this->config['blog']['url'] . '/public/([.A-Za-z0-9_/-]*)\\\"^', $xml, $matches);
        $files2 = array_unique($matches[1]);
        $files = array_unique(array_merge($files, $files2));
        echo count($files) . " images found.\n";

        return $files;
    }

    public function createResizedImages($imagesInPost, $xml)
    {
        echo "    Converting resized images: ";
        // Si l'image n'existe pas, on essaye de la recréer
        $nbImagesCreated = 0 ;
        foreach ($imagesInPost as $index => $file) {
            // le fichier n'existe pas ? Il a du être retaillé et pas exporté par Dotclear :(
            if (!file_exists($this->config['media']['source_dir'] . DIRECTORY_SEPARATOR . $file)) {
                // Pas grave, on va le recréer !
                $res = $this->createImage($file);
                if ($res !== false) {
                    $this->medias[$res['original_pathname']] = $res ;
                    $nbImagesCreated ++ ;
                } else {
                    $errors[] = $res ;
                }
            }
        }
        echo "$nbImagesCreated image(s) created.\n";

        return $xml;
    }

    private function createImage($file)
    {
        $sourceDir = $this->config['media']['source_dir'] ;
        $targetDir = $this->config['media']['target_dir'] ;

        // get original filename
        $pathInfo = pathinfo($sourceDir . DIRECTORY_SEPARATOR . $file);

        // Get original filename
        $size = substr($pathInfo['filename'], -2);
        $filename = substr($pathInfo['filename'], 1, -2);
        $realname = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $filename . '.' . $pathInfo['extension'];
        $format = $pathInfo['extension'];

        if (!file_exists($realname)) {
            // DC met ses vignettes en jpg, mais l'orignal est peut-être en png. On check.
            $realname = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $filename . '.png';
            $format = 'png';
            if (!file_exists($realname)) {
                echo "\n !!! ERROR: File not found: $file => Image will not be processed.\n    ";
                return false;
            }
        }

        list($width, $height, $type, $attr) = getimagesize($realname);
        list($newHeight, $newWidth) = $this->getFinalSize($size, $width, $height);

        // Nouvelle image
        $dest = imagecreatetruecolor($newWidth, $newHeight);
        if ($format === 'jpg') {
            $source = imagecreatefromjpeg($realname);
        } else {
            $source = imagecreatefrompng($realname);
        }
        // On retaille
        imagecopyresized($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $newFilename = str_replace(['/', '\\'], '_', $file);
        $newPath = gmdate('Y/m', filectime($realname)) .'/';
        if (!file_exists($targetDir . '/' . $newPath)) {
            mkdir($targetDir . '/' . $newPath, 0777, true);
        }
        if ($format === 'jpg') {
            imagejpeg($dest, $targetDir . '/' . $newPath . $newFilename);
        } else {
            imagepng($dest, $targetDir . '/' . $newPath . $newFilename);
        }
        imagedestroy($source);
        imagedestroy($dest);

        $filetime = filectime($realname);
        touch($targetDir . '/' . $newPath . $newFilename, $filetime);

        return [
            'original_pathname' => $file,
            'new_pathname' => $newPath .  $newFilename,
        ];

    }


    protected function generateXml($dotclear)
    {
        echo "    Generating XML\n";
        $dom = new DOMDocument('1.0', 'UTF-8');
        // Pour un formatage lisible
        $dom->formatOutput = true;

        $this->addRssNode($dom, $dotclear);

        return $dom->saveXML();
    }

    protected function writeOutput($wordpressFile, $content)
    {
        echo "Writing " . $wordpressFile . "...";
        $res = file_put_contents($wordpressFile, $content);
        echo " done !\n";

        return $res;
    }

    protected function readInput($filename)
    {
        $dotclear = '';
        $filecontent = [];

        try {
            $filecontent = file($filename, FILE_IGNORE_NEW_LINES);
        } catch (ErrorException $e) {
            echo "  Error: " . $e->getMessage();
            die;
        }

        $organizedContent = [];
        $type = null;
        foreach ($filecontent as $numero => $line) {
            // if line starts by //, we skip it.
            // if empty line, we skip it.
            if ('//' === substr($line, 0, 2) || '' === $line) {
                continue;
            }

            // if line starts by [ and end by ], it is a section header
            if ('[' === $line[0] && ']' === substr($line, -1)) {
                $line = substr($line, 1, -1);
                list($type, $labels) = explode(' ', $line);
                $keys = explode(',', $labels);
                continue;
            }
            // C'est une ligne de contenu
            // Si pas de type défini, erreur
            if (null === $type) {
                echo "  Error: Header line not found line $numero.\n";
                die;
            }

            $values = str_getcsv($line);
            try {
                $organizedContent[$type][] = array_combine($keys, $values);
            } catch (ErrorException $e) {
                echo "  Error: " . $e->getMessage();
                die;
            }
        }

        return $organizedContent;
    }

    protected function init()
    {
        echo "Dotclear to Wordpress Convertor v" . $this->version . "\n";
    }

    protected function getOptions()
    {
        $longOpts = [
            "input::",
            "output::",
            "media::",
        ];

        $options = getopt(null, $longOpts);

        if (empty($options['input'])) {
            $this->printUsage();
            die;
        }

        if (empty($options['output'])) {
            $options['output'] = 'wordpress.xml';
        }

        if (empty($options['media'])) {
            $options['media'] = '';
        }

        return $options;
    }


    protected function printUsage()
    {
        echo "    Usage :\n";
        echo "        " . basename(__FILE__) . " --input=<dotclear_flat_file.txt> --output=<" .
            $this->defaultOutputFilename . ">\n";
        echo "    Options :\n";
        echo "        - input : Mandatory. The name of the Dotclear flatfile to convert.\n";
        echo "        - output: Optionnal. The name of the converted file in Worpress format.\n";
        echo "          Default value is " . $this->defaultOutputFilename . ".\n";
    }

    protected function printDotClearStats($dotclear)
    {
        echo "    Fichier Dotclear lu. Résumé :\n";
        foreach ($dotclear as $section => $lines) {
            echo "    - " . ucfirst($section) . ": " . count($lines) . " entrées trouvées.\n";
        }
    }

    /**
     * @param DOMDocument $dom
     * @param array $dotclearData
     * @throws Exception
     */
    protected function addRssNode(DOMDocument $dom, $dotclearData)
    {
        $rssNode = $dom->createElement('rss');

        $domAttribute = $dom->createAttribute('version');
        $domAttribute->value = '2.0';
        $rssNode->appendChild($domAttribute);

        $domAttribute = $dom->createAttribute('xmlns:excerpt');
        $domAttribute->value = 'http://wordpress.org/export/1.2/excerpt/';
        $rssNode->appendChild($domAttribute);

        $domAttribute = $dom->createAttribute('xmlns:content');
        $domAttribute->value = 'http://purl.org/rss/1.0/modules/content/';
        $rssNode->appendChild($domAttribute);

        $domAttribute = $dom->createAttribute('xmlns:wfw');
        $domAttribute->value = 'http://wellformedweb.org/CommentAPI/';
        $rssNode->appendChild($domAttribute);

        $domAttribute = $dom->createAttribute('xmlns:dc');
        $domAttribute->value = 'http://purl.org/dc/elements/1.1/';
        $rssNode->appendChild($domAttribute);

        $domAttribute = $dom->createAttribute('xmlns:wp');
        $domAttribute->value = 'http://wordpress.org/export/1.2/';
        $rssNode->appendChild($domAttribute);

        $this->addChannelNode($dom, $rssNode, $dotclearData);

        $dom->appendChild($rssNode);

    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $rssNode
     * @param array $dotclearData
     * @throws Exception
     */
    protected function addChannelNode(DOMDocument $dom, DOMElement $rssNode, array $dotclearData)
    {
        $channelNode = $dom->createElement('channel');

        $this->addGeneratorNode($dom, $channelNode);
        $this->addTitleNode($dom, $channelNode);
        $this->addLinkNode($dom, $channelNode);
        $this->addDescriptionNode($dom, $channelNode);
        $this->addPublicationDateNode($dom, $channelNode);
        $this->addLanguageNode($dom, $channelNode);
        $this->addWxrVersionNode($dom, $channelNode);
        $this->addBaseUrlNode($dom, $channelNode);

        $this->addAuthorNode($dom, $channelNode, $dotclearData);

        // On s'occupe d'abord des catégories
        foreach ($dotclearData['category'] as $key => $category) {
            $this->addCategoryNode($dom, $category, $channelNode);
            // Pour chaque catégorie, on ajoute aussi un term
            $this->addTermNodeFromCategory($dom, $category, $channelNode);
        }
        // On ajoute maintenant les tags
        // On s'occupe d'abord des catégories
        if (array_key_exists('tag', $dotclearData)) {
            foreach ($dotclearData['tag'] as $key => $tag) {
                $this->addTagNode($dom, $tag, $channelNode);
                // Pour chaque tag, on ajoute aussi un term
                $this->addTermNodeFromTag($dom, $tag, $channelNode);
            }
        }

        // On convertit les medias
        $this->medias = $medias = $this->convertMedia();
        foreach ($medias as $key => $media) {
            $this->addItemNodeFromMedia($dom, $media, $channelNode);
        }

        // On s'occupe des articles
        foreach ($dotclearData['post'] as $key => $post) {
            $this->addItemNode($dom, $post, $channelNode);
        }


        $rssNode->appendChild($channelNode);
    }

    protected function addItemNode(DOMDocument $dom, $post, DOMElement $channelNode)
    {
        if (!in_array($post['post_type'], ['post', 'page'])) {
            echo "ERROR: Unknown post_type.\n";
            var_dump($post);
            die;
        }
        $itemNode = $dom->createElement('item');

        $node = $dom->createElement('title', htmlentities($post['post_title']));
        $itemNode->appendChild($node);

        $node = $dom->createElement(
            'link',
            $this->config['blog']['url'] . '/post/' . $post['post_url']
        );
        $itemNode->appendChild($node);

        $date = new DateTime($post['post_creadt']);
        $node = $dom->createElement(
            'pubDate',
            $date->format('D, j M Y H:i:s O')
        );
        $itemNode->appendChild($node);

        $node = $dom->createElement('dc:creator');
        $cdata = $dom->createCDATASection($this->config['author']['display_name']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement(
            'guid',
            $this->config['blog']['url'] . '/?p=' . $post['post_id']);
        $domAttribute = $dom->createAttribute('isPermaLink');
        $domAttribute->value = 'false';
        $node->appendChild($domAttribute);
        $itemNode->appendChild($node);

        $node = $dom->createElement('description');
        $itemNode->appendChild($node);

        // On supprime les inutiles\r\n que DC a mis partout
        $post['post_content'] = str_replace('\r\n', " ", $post['post_content']);
        $post['post_content'] = str_replace('\n', " ", $post['post_content']);
        $post['post_content'] = str_replace('\n', " ", $post['post_content']);

        $node = $dom->createElement('content:encoded');
        $cdata = $dom->createCDATASection($post['post_content']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('excerpt:encoded');
        $cdata = $dom->createCDATASection($post['post_excerpt']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_id', $post['post_id']);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_date');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_date_gmt');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:comment_status');
        $cdata = $dom->createCDATASection($post['post_open_comment'] === "1" ? 'open' : 'closed');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:ping_status');
        $cdata = $dom->createCDATASection($post['post_open_tb'] === "1" ? 'open' : 'closed');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_name');
        $cdata = $dom->createCDATASection($this->slugify($post['post_title']));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        if (!in_array(intval($post['post_status']), [-2, 0, 1])) {
            echo "\n-----\n  -> Unknown post status\n-----\n";
            var_dump($post);
            die;
        }
        $status = 'publish';
        switch ($post['post_status']) {
            case 1 :
                $status = 'publish';
                break;
            case 0 :
                $status = 'trash';
                break;
            case -2:
            default:
                $status = 'draft';
                break;
        }
        $node = $dom->createElement('wp:status');
        $cdata = $dom->createCDATASection($status);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_parent', 0);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:menu_order', 0);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_type');
        $cdata = $dom->createCDATASection($post['post_type']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_password');
        $cdata = $dom->createCDATASection($post['post_password']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:is_sticky', $post['post_selected']);
        $itemNode->appendChild($node);

        // On ajoute les tags de ce post
        $metas = unserialize(stripcslashes($post['post_meta']));
        if ($metas === false) {
            $metas = [];
        }
        foreach ($metas as $type => $meta) {
            if ($type === "tag") {
                foreach ($meta as $tag) {
                    $node = $dom->createElement('category');
                    $cdata = $dom->createCDATASection($tag);
                    $domAttribute = $dom->createAttribute('domain');
                    $domAttribute->value = 'post_tag';
                    $node->appendChild($domAttribute);
                    $domAttribute = $dom->createAttribute('nicename');
                    $domAttribute->value = $this->slugify($tag);
                    $node->appendChild($domAttribute);
                    $node->appendChild($cdata);
                    $itemNode->appendChild($node);
                }
            } else {
                echo "\n-----\n  -> Unknown meta type\n-----\n";
                var_dump($type);
                die;
            }
        }

        // On ajoute la catégorie de ce post
        $catId = intval($post['cat_id']);
        if ($catId !== 0) {
            $category = $this->dotclear['category'][$catId];

            $node = $dom->createElement('category');
            $cdata = $dom->createCDATASection($category['cat_title']);
            $domAttribute = $dom->createAttribute('domain');
            $domAttribute->value = 'category';
            $node->appendChild($domAttribute);
            $domAttribute = $dom->createAttribute('nicename');
            $domAttribute->value = strtolower($category['cat_url']);
            $node->appendChild($domAttribute);
            $node->appendChild($cdata);
            $itemNode->appendChild($node);
        }

        // On ajoute les commentaires
        //var_dump($this->dotclear['comment']);die;
        foreach ($this->dotclear['comment'] as $comment) {
            if ($comment['post_id'] !== $post['post_id']) {
                //var_dump($comment['comment_status']);
                continue;
            }
            $node = $this->getCommentNode($dom, $comment);
            $itemNode->appendChild($node);
            //var_dump($comment);die;

        }

        $channelNode->appendChild($itemNode);
    }

    protected function addItemNodeFromMedia(DOMDocument $dom, $media, DOMElement $channelNode)
    {
        static $postId = 5000;
        $sourceDir = $this->config['media']['source_dir'];
        $targetDir = $this->config['media']['target_dir'];
        $pathInfo = pathinfo($targetDir . DIRECTORY_SEPARATOR . $media['new_pathname']);

        $itemNode = $dom->createElement('item');

        $node = $dom->createElement('title', $pathInfo['filename']);
        $itemNode->appendChild($node);

        $node = $dom->createElement(
            'link',
            $this->config['blog']['url'] . '/image/' . $pathInfo['filename'] . '/'
        );
        $itemNode->appendChild($node);

        $date = new DateTime();
        $node = $dom->createElement(
            'pubDate',
            $date->format('D, j M Y H:i:s O')
        );
        $itemNode->appendChild($node);

        $node = $dom->createElement('dc:creator');
        $cdata = $dom->createCDATASection($this->config['author']['display_name']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement(
            'guid',
            $this->config['blog']['url'] . '/wp-content/uploads/' . $media['new_pathname']);
        $domAttribute = $dom->createAttribute('isPermaLink');
        $domAttribute->value = 'false';
        $node->appendChild($domAttribute);
        $itemNode->appendChild($node);

        $node = $dom->createElement('description');
        $itemNode->appendChild($node);

        $node = $dom->createElement('content:encoded');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('excerpt:encoded');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_id', $postId++);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_date');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_date_gmt');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:comment_status');
        $cdata = $dom->createCDATASection('closed');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:ping_status');
        $cdata = $dom->createCDATASection('closed');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_name');
        $cdata = $dom->createCDATASection($this->slugify($pathInfo['filename']));
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:status');
        $cdata = $dom->createCDATASection('inherit');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_parent', 0);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:menu_order', 0);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_type');
        $cdata = $dom->createCDATASection('attachment');
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:post_password');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:is_sticky', 0);
        $itemNode->appendChild($node);

        $blog = $this->config['blog'];
        $node = $dom->createElement('wp:attachment_url');
        $cdata = $dom->createCDATASection($blog['url']. '/'. $blog['image_base_path'] . '/' . $media['new_pathname']);
        $node->appendChild($cdata);
        $itemNode->appendChild($node);

        $node = $dom->createElement('wp:postmeta');
        $subNode = $dom->createElement('wp:meta_key');
        $cdata = $dom->createCDATASection('_wp_attached_file');
        $subNode->appendChild($cdata);
        $node->appendChild($subNode);
        $subNode = $dom->createElement('wp:meta_value');
        $cdata = $dom->createCDATASection($media['new_pathname']);
        $subNode->appendChild($cdata);
        $node->appendChild($subNode);
        $itemNode->appendChild($node);

        $channelNode->appendChild($itemNode);
    }

    protected function getCommentNode(DOMDocument $dom, $comment)
    {
        $commentNode = $dom->createElement('wp:comment');

        $node = $dom->createElement('wp:comment_id', intval($comment['comment_id']));
        $commentNode->appendChild($node);

        $node = $dom->createElement('wp:comment_author');
        $cdata = $dom->createCDATASection($comment['comment_author']);
        $node->appendChild($cdata);
        $commentNode->appendChild($node);

        $node = $dom->createElement('wp:comment_author_email');
        $cdata = $dom->createCDATASection($comment['comment_email']);
        $node->appendChild($cdata);
        $commentNode->appendChild($node);

        $node = $dom->createElement('wp:comment_author_url', $comment['comment_site']);
        $commentNode->appendChild($node);

        $node = $dom->createElement('wp:comment_author_IP');
        $cdata = $dom->createCDATASection($comment['comment_ip']);
        $node->appendChild($cdata);
        $commentNode->appendChild($node);

        $date = new DateTime($comment['comment_dt']);

        $node = $dom->createElement('wp:comment_date');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $commentNode->appendChild($node);
        $node = $dom->createElement('wp:comment_date_gmt');
        $cdata = $dom->createCDATASection($date->format('Y-m-d H:i:s'));
        $node->appendChild($cdata);
        $commentNode->appendChild($node);

        // On supprime les inutiles\r\n que DC a mis partout
        $comment['comment_content'] = str_replace('\r\n', " ", $comment['comment_content']);
        $comment['comment_content'] = str_replace('\n', " ", $comment['comment_content']);
        $comment['comment_content'] = str_replace('\n', " ", $comment['comment_content']);

        $node = $dom->createElement('wp:comment_content');
        $cdata = $dom->createCDATASection($comment['comment_content']);
        $node->appendChild($cdata);
        $commentNode->appendChild($node);

        if ($comment['comment_status'] === "-1") {
            //var_dump($comment); die;
        }

        if ($comment['comment_spam_status'] !== "0") {
            echo "\n-----\n  -> Unknown spam status\n-----\n";
            var_dump($comment);
            die;
        }

        $approved = 1;
        // https://developer.wordpress.org/reference/functions/wp_set_comment_status/
        switch ($comment['comment_status']) {
            // Approved
            case 1 :
                $approved = 1;
                break;
            // Hold
            case 0 :
                $approved = 0;
                break;
            // Trash
            case -1:
            case "-1":
                $approved = 'post-trashed';
                break;
        }

        if (is_string($approved)) {
            $node = $dom->createElement('wp:comment_approved');
            $cdata = $dom->createCDATASection($approved);
            $node->appendChild($cdata);
        } else {
            $node = $dom->createElement('wp:comment_approved', $approved);
        }
        $commentNode->appendChild($node);

        if ($comment['comment_trackback'] !== "0") {
            echo "\n-----\n  -> Unknown comment_trackback\n-----\n";
            var_dump($comment);
            die;
        }
        if ($comment['comment_spam_filter'] !== "") {
            echo "\n-----\n  -> Unknown comment_spam_filter\n-----\n";
            var_dump($comment);
            die;
        }

        $node = $dom->createElement('wp:comment_parent', 0);
        $commentNode->appendChild($node);

        $node = $dom->createElement('wp:comment_user_id', 0);
        $commentNode->appendChild($node);

        return $commentNode;
    }


    protected function addAuthorNode(DOMDocument $dom, DOMElement $channelNode, $dotclear)
    {
        // Dans l'export DC de Gandhi, on n'a pas d'auteurs :(.
        // Mais dans les settings, on a un "editor"
        foreach ($dotclear['setting'] as $setting) {
            if ('editor' === $setting['setting_id']) {
                $authorNode = $dom->createElement('wp:author');

                $node = $dom->createElement('wp:author_id', 1);
                $authorNode->appendChild($node);

                $node = $dom->createElement('wp:author_login');
                $cdata = $dom->createCDATASection($this->config['author']['login']);
                $node->appendChild($cdata);
                $authorNode->appendChild($node);

                $node = $dom->createElement('wp:author_email');
                $cdata = $dom->createCDATASection($this->config['author']['email']);
                $node->appendChild($cdata);
                $authorNode->appendChild($node);

                $node = $dom->createElement('wp:author_display_name');
                $cdata = $dom->createCDATASection($this->config['author']['display_name']);
                $node->appendChild($cdata);
                $authorNode->appendChild($node);

                $node = $dom->createElement('wp:author_first_name');
                $cdata = $dom->createCDATASection($this->config['author']['first_name']);
                $node->appendChild($cdata);
                $authorNode->appendChild($node);

                $node = $dom->createElement('wp:author_last_name');
                $cdata = $dom->createCDATASection($this->config['author']['last_name']);
                $node->appendChild($cdata);
                $authorNode->appendChild($node);

                $channelNode->appendChild($authorNode);
            }
        }

    }

    /**
     * @param DOMDocument $dom
     * @param $category
     * @param DOMElement $channelNode
     * @return string
     */
    protected function addCategoryNode(DOMDocument $dom, $category, DOMElement $channelNode)
    {
        $categoryNode = $dom->createElement('wp:category');

        $node = $dom->createElement('wp:term_id', $category['cat_id']);
        $categoryNode->appendChild($node);

        $node = $dom->createElement('wp:category_nicename');
        $cdata = $dom->createCDATASection(strtolower($category['cat_url']));
        $node->appendChild($cdata);
        $categoryNode->appendChild($node);

        $node = $dom->createElement('wp:category_parent');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $categoryNode->appendChild($node);

        $node = $dom->createElement('wp:cat_name');
        $cdata = $dom->createCDATASection($category['cat_title']);
        $node->appendChild($cdata);
        $categoryNode->appendChild($node);

        $channelNode->appendChild($categoryNode);

        return $category['cat_id'];
    }

    /**
     * @param DOMDocument $dom
     * @param $tag
     * @param DOMElement $channelNode
     * @return string
     */
    protected function addTagNode(DOMDocument $dom, $tag, DOMElement $channelNode)
    {
        $categoryNode = $dom->createElement('wp:tag');

        $node = $dom->createElement('wp:term_id', $tag['id']);
        $categoryNode->appendChild($node);

        $node = $dom->createElement('wp:tag_slug');
        $cdata = $dom->createCDATASection($this->slugify($tag['label']));
        $node->appendChild($cdata);
        $categoryNode->appendChild($node);

        $node = $dom->createElement('wp:tag_name');
        $cdata = $dom->createCDATASection($tag['label']);
        $node->appendChild($cdata);
        $categoryNode->appendChild($node);

        $channelNode->appendChild($categoryNode);
    }

    /**
     * @param DOMDocument $dom
     * @param $category
     * @param DOMElement $channelNode
     */
    protected function addTermNodeFromCategory(DOMDocument $dom, $category, DOMElement $channelNode)
    {
        $termNode = $dom->createElement('wp:term');

        $node = $dom->createElement('wp:term_id');
        $cdata = $dom->createCDATASection($category['cat_id']);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_taxonomy');
        $cdata = $dom->createCDATASection('category');
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_slug');
        $cdata = $dom->createCDATASection(strtolower($category['cat_url']));
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_parent');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_name');
        $cdata = $dom->createCDATASection($category['cat_title']);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $channelNode->appendChild($termNode);

    }

    /**
     * @param DOMDocument $dom
     * @param $tag
     * @param DOMElement $channelNode
     */
    protected function addTermNodeFromTag(DOMDocument $dom, $tag, DOMElement $channelNode)
    {
        $termNode = $dom->createElement('wp:term');

        $node = $dom->createElement('wp:term_id');
        $cdata = $dom->createCDATASection($tag['id']);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_taxonomy');
        $cdata = $dom->createCDATASection('post_tag');
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_slug');
        $cdata = $dom->createCDATASection($this->slugify($tag['label']));
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_parent');
        $cdata = $dom->createCDATASection(null);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $node = $dom->createElement('wp:term_name');
        $cdata = $dom->createCDATASection($tag['label']);
        $node->appendChild($cdata);
        $termNode->appendChild($node);

        $channelNode->appendChild($termNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addGeneratorNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'generator',
            $this->projectName . ' ' . $this->version
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addTitleNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'title',
            'Mon blog'
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addLinkNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'link',
            $this->config['blog']['url']
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addBaseUrlNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'wp:base_site_url',
            $this->config['blog']['url']
        );
        $channelNode->appendChild($generatorNode);

        $generatorNode = $dom->createElement(
            'wp:base_blog_url',
            $this->config['blog']['url']
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addDescriptionNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'description',
            'Description de mon blog'
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     * @throws Exception
     */
    protected function addPublicationDateNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $now = new DateTime();
        $generatorNode = $dom->createElement(
            'pubDate',
            $now->format('D, j M Y H:i:s O')
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addLanguageNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'language',
            'fr-FR'
        );
        $channelNode->appendChild($generatorNode);
    }

    /**
     * @param DOMDocument $dom
     * @param DOMElement $channelNode
     */
    protected function addWxrVersionNode(DOMDocument $dom, DOMElement $channelNode)
    {
        $generatorNode = $dom->createElement(
            'wp:wxr_version',
            '1.2'
        );
        $channelNode->appendChild($generatorNode);
    }

    protected function dotclearConvertCategories(array $dotclear)
    {
        $newCats = [];
        foreach ($dotclear['category'] as $category) {
            $newCats[$category['cat_id']] = $category;
        }
        $dotclear['category'] = $newCats;

        return $dotclear;
    }

    /**
     * @param array $dotclear
     * @return array
     */
    protected function dotclearConvertMeta(array $dotclear)
    {
        if (array_key_exists('meta', $dotclear)) {
            if (!array_key_exists('tag', $dotclear)) {
                $dotclear['tag'] = [];
            }
            foreach ($dotclear['meta'] as $id => $value) {
                if ($value['meta_type'] === 'tag') {
                    // les meta tag sont des terms WP
                    $name = $value['meta_id'];
                    if (!array_key_exists($name, $dotclear['tag'])) {
                        $id = $this->maxTermid++;
                        $dotclear['tag'][$name] = [
                            'id' => $id,
                            'article' => [],
                            'label' => $name,
                        ];
                    }
                    $dotclear['tag'][$name]['article'][] = $value['post_id'];
                    unset ($dotclear['meta'][$id]);
                } else {
                    echo "Warning : unknown meta_type : " . $value['meta_type'] . "\n";
                }
            }
            if (count($dotclear['meta']) === 0) {
                unset($dotclear['meta']);
            }
        }
        return $dotclear;
    }


    public function slugify($text)
    {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }

    /**
     * @param $size
     * @param $width
     * @param $height
     * @return array
     */
    private function getFinalSize($size, $width, $height)
    {
        $max = 448;
        if ($size === '_s') {
            $max = 240;
        }

        if ($width > $max) {
            $height = $max * $height / $width;
            $width = $max;
        }
        if ($height > $max) {
            $width = $max * $width / $height;
            $height = $max;
        }

        return array((int)$height, (int)$width);
    }

    /**
     * @param $xml
     * @return string
     */
    private function replaceMediaPath($xml)
    {
        $blog = $this->config['blog'];
        foreach ($this->medias as $key => $media) {
            $src = "/public/" . str_replace('\\', '/', $media['original_pathname']);
            $dest =  '/' . $blog['image_base_path'] . '/' . $media['new_pathname'];
            //echo $src . "     =>    " . $dest . "\n" ;
            // maintenant on remplace dans le XML les anciens chemins par les nouveaux
            $xml = str_replace('src=\"' . $src, 'src=\"' . $dest, $xml);
            $xml = str_replace('src=\"' . $blog['url'] . $src, 'src=\"' . $dest, $xml);
            $xml = str_replace('href=\"' . $src, 'href=\"' . $dest, $xml);
            $xml = str_replace('href=\"' . $blog['url'] . $src, 'href=\"' . $dest, $xml);
        }
        return $xml;
    }
}
