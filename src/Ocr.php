<?php

namespace Wikisource\GoogleOcr;

use Wikisource\GoogleCloudVisionPHP\GoogleCloudVision;

class Ocr
{

    /** @var string The URL of the image to process. */
    protected $image;

    /** @var string The two-letter language code of the text in the image. */
    protected $lang;

    /** @var Wikisource\GoogleCloudVisionPHP\GoogleCloudVision */
    protected $gcv;

    public function __construct($baseDir, $request)
    {
        // Get the configuration variables.
        require $baseDir . '/config.php';
        if (empty($key)) {
            throw new \Exception('API key value ($key) must be set in config.php');
        }
        $this->gcv = new GoogleCloudVision();
        $this->gcv->setKey($key);
        if (!empty($endpoint)) {
            $this->gcv->setEndpoint($endpoint);
        }

        // Get and check the request parameters.
        if (!empty($request['image'])) {
            $this->image = $request['image'];
        }
        if (!empty($request['lang'])) {
            $this->lang = $request['lang'];
        }
    }

    public function getImage()
    {
        return $this->image;
    }

    public function getLang()
    {
        return $this->lang;
    }

    public function hasValidImage()
    {
        if (!isset($this->image)) {
            return false;
        }
        $uploadUrl = 'https://upload.wikimedia.org/';
        if (substr($this->image, 0, strlen($uploadUrl)) !== $uploadUrl) {
            return false;
        }
        return true;
    }

    public function getText()
    {
        $this->gcv->setImage($this->image);
        $this->gcv->addFeatureOCR();
        if ($this->getLang() !== null && $this->getLang() !== 'en') {
            $this->gcv->setImageContext(['languageHints' => [$_REQUEST['lang']]]);
        }
        $response = $this->gcv->request();

        // Check for errors and pass any through.
        if (isset($response['responses'][0]['error']['message'])) {
            throw new \Exception($response['responses'][0]['error']['message']);
        }

        // Return only the text to the user (it's not an error if there's no text).
        $text = '';
        if (isset($response['responses'][0]['textAnnotations'][0]['description'])) {
            $text = $response['responses'][0]['textAnnotations'][0]['description'];
        }
        return $text;
    }
}