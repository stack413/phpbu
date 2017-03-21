<?php
namespace phpbu\App\Backup\Sync;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use phpbu\App\Result;
use phpbu\App\Backup\Sync;
use phpbu\App\Backup\Target;

/**
 * Amazon S3 Sync
 *
 * @package    phpbu
 * @subpackage Backup
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 * @since      Class available since Release 3.0.0
 */
class AmazonS3v3 extends AmazonS3
{
    /**
     * Execute the sync.
     *
     * @see    \phpbu\App\Backup\Sync::sync()
     * @param  \phpbu\App\Backup\Target $target
     * @param  \phpbu\App\Result        $result
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    public function sync(Target $target, Result $result)
    {
        $s3 = S3Client::factory([
            'region'  => $this->region,
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => $this->key,
                'secret' => $this->secret,
            ]
        ]);

        try {
            if ($this->useMultiPartUpload($target)) {
                $this->uploadMultiPart($target, $s3);
            } else {
                $this->uploadStream($target, $s3);
            }
        } catch (\Exception $e) {
            throw new Exception($e->getMessage(), null, $e);
        }
        $result->debug('upload: done');
    }

    /**
     * Upload via stream wrapper.
     *
     * @param  \phpbu\App\Backup\Target $target
     * @param  \Aws\S3\S3Client         $s3
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    private function uploadStream(Target $target, S3Client $s3)
    {
        $s3->registerStreamWrapper();
        $source = $this->getFileHandle($target->getPathname(), 'r');
        $stream = $this->getFileHandle('s3://' . $this->bucket . '/' . $this->getUploadPath($target), 'w');
        while(!feof($source)) {
            fwrite($stream, fread($source, 4096));
        }
        fclose($stream);
    }

    /**
     * Upload via multi part.
     *
     * @param \phpbu\App\Backup\Target $target
     * @param \Aws\S3\S3Client         $s3
     * @param \Aws\Exception\MultipartUploadException
     */
    private function uploadMultiPart(Target $target, S3Client $s3)
    {
        $uploader = new MultipartUploader($s3, $target->getPathname(), [
            'bucket' => $this->bucket,
            'key'    => $this->getUploadPath($target),
        ]);
        $uploader->upload();
    }

    /**
     * Open stream and validate it.
     *
     * @param  string $path
     * @param  string $mode
     * @return resource
     * @throws \phpbu\App\Backup\Sync\Exception
     */
    private function getFileHandle($path, $mode)
    {
        $handle = fopen($path, $mode);
        if (!is_resource($handle)) {
            throw new Exception('fopen failed: could not open stream ' . $path);
        }
        return $handle;
    }

    /**
     * Get the s3 upload path
     *
     * @param \phpbu\App\Backup\Target $target
     * @return string
     */
    public function getUploadPath(Target $target)
    {
        // remove leading slash
        return (substr($this->path, 0, 1) == '/' ? substr($this->path, 1) : $this->path)
               . (substr($this->path, -1, 1) == '/' ? '' : '/')
               . $target->getFilename();
    }
}
