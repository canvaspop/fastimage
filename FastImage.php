<?php

namespace FastImage;

/**
 * FastImage - Because sometimes you just want the size!
 * Based on the Ruby Implementation by Steven Sykes (https://github.com/sdsykes/fastimage).
 * Forked from Tom Moor PHP port (https://github.com/tommoor/fastimage)
 *
 * MIT Licensed
 */
class FastImage
{
    private $strpos = 0;
    private $str;

    /**
     * @var string image type
     */
    private $type;

    /**
     * @var resource
     */
    private $handle;

    /**
     * @var resource
     */
    private $context;

    /**
     * @param array|null $headers HTTP headers such as ['Accept-language: en', '...']
     */
    public function __construct(array $headers = null)
    {
        $options = ['http' => [
            'method'    => 'GET',
        ]];

        if ($headers) {
            $options['http']['header'] = implode("\r\n", $headers);
        }

        $this->context = stream_context_create($options);
    }

    /**
     * Open a remote file
     *
     * @param string $uri
     * @throws Exception if fopen() fails
     */
    public function load($uri)
    {
        if ($this->handle) {
            $this->close();
        }

        if (!$this->handle = @fopen($uri, 'rb', false, $this->context)) {
            throw new \Exception(sprintf('An error occurred while running fopen()'));
        }
    }

    /**
     * Close an open remote "file"
     */
    public function close()
    {
        if ($this->handle) {
            fclose($this->handle);

            $this->handle = null;
            $this->type = null;
            $this->str = null;
        }
    }

    /**
     * @return array|false get size height, width
     */
    public function getSize()
    {
        $this->strpos = 0;

        if ($this->getType()) {
            return array_values($this->parseSize());
        }

        return false;
    }

    /**
     * @return string|false get image type (bmp, jpeg...)
     */
    public function getType()
    {
        $this->strpos = 0;

        if (!$this->type) {
            switch ($this->getChars(2)) {
                case 'BM':
                    return $this->type = 'bmp';
                case 'GI':
                    return $this->type = 'gif';
                case chr(0xFF).chr(0xd8):
                    return $this->type = 'jpeg';
                case chr(0x89).'P':
                    return $this->type = 'png';
                case 'RI':
                    return $this->type = 'webp';
                default:
                    return false;
            }
        }

        return $this->type;
    }

    private function parseSize()
    {
        $this->strpos = 0;

        switch ($this->type) {
            case 'png':
                return $this->parseSizeForPNG();
            case 'gif':
                return $this->parseSizeForGIF();
            case 'bmp':
                return $this->parseSizeForBMP();
            case 'jpeg':
                return $this->parseSizeForJPEG();
            case 'webp':
                return $this->parseSizeForWEBP();
        }

        return;
    }

    private function parseSizeForPNG()
    {
        $chars = $this->getChars(25);

        return unpack('N*', substr($chars, 16, 8));
    }

    private function parseSizeForGIF()
    {
        $chars = $this->getChars(11);

        return unpack('S*', substr($chars, 6, 4));
    }

    private function parseSizeForBMP()
    {
        $chars = $this->getChars(29);
        $chars = substr($chars, 14, 14);
        $type = unpack('C', $chars);

        return (reset($type) == 40) ? unpack('L*', substr($chars, 4)) : unpack('L*', substr($chars, 4, 8));
    }

    private function parseSizeForJPEG()
    {
        $state = null;
        $i = 0;

        while (true) {
            switch ($state) {
                default:
                    $this->getChars(2);
                    $state = 'started';
                    break;

                case 'started':
                    $b = $this->getByte();
                    if ($b === false) {
                        return false;
                    }

                    $state = $b == 0xFF ? 'sof' : 'started';
                    break;

                case 'sof':
                    $b = $this->getByte();
                    if (in_array($b, range(0xe0, 0xef))) {
                        $state = 'skipframe';
                    } elseif (in_array($b, array_merge(range(0xC0, 0xC3), range(0xC5, 0xC7), range(0xC9, 0xCB), range(0xCD, 0xCF)))) {
                        $state = 'readsize';
                    } elseif ($b == 0xFF) {
                        $state = 'sof';
                    } else {
                        $state = 'skipframe';
                    }
                    break;

                case 'skipframe':
                    $skip = $this->readInt($this->getChars(2)) - 2;
                    $state = 'doskip';
                    break;

                case 'doskip':
                    $this->getChars($skip);
                    $state = 'started';
                    break;

                case 'readsize':
                    $c = $this->getChars(7);

                    return array($this->readInt(substr($c, 5, 2)), $this->readInt(substr($c, 3, 2)));
            }
        }
    }

    private function parseSizeForWEBP()
    {
        $chars = $this->getChars(30);
        $result = unpack('C12/S9', $chars);

        return array($result['8'], $result['9']);
    }

    private function getChars($n)
    {
        $response = null;

        // do we need more data?
        if ($this->strpos + $n - 1 >= strlen((string)$this->str)) {
            $end = ($this->strpos + $n);

            while (strlen((string)$this->str) < $end && $response !== false) {
                // read more from the file handle
                $need = $end - ftell($this->handle);

                if ($response = fread($this->handle, $need)) {
                    $this->str .= $response;
                } else {
                    return false;
                }
            }
        }

        $result = substr($this->str, $this->strpos, $n);
        $this->strpos += $n;

        return $result;
    }

    private function getByte()
    {
        $c = $this->getChars(1);
        $b = unpack('C', $c);

        return reset($b);
    }

    private function readInt($str)
    {
        $size = unpack('C*', $str);

        return ($size[1] << 8) + $size[2];
    }

    public function __destruct()
    {
        $this->close();
    }
}
