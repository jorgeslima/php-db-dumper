<?php
/**
 * This program extract a dump of a specific database, compress it and send it to AWS S3 or Save it locally.
 * The main use of it is through CLI or cron.
 * It preserves the latest 3 versions of the dump avoid storing too much files
 *
 * @author Jorge Lima <jorge@creativeconcept.com.br>
 * @version 0.1
 */

namespace DbDumper;
require_once('vendor/autoload.php');

use Dotenv\Dotenv;
use Ifsnop\Mysqldump as MySQLDumper;
use Aws\S3\S3Client;
use Aws\S3\S3Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

/**
 * Main class of the program.
 *
 *  Please note: All of this class parameters are defined by .env file so you do not have to edit anything in this class unless if you
 *  want to extend some funcionality.
 *
 */
class DbDumper{

    /**
     * The database username
     *
     * @var string
     */
    private $db_user;

    /**
     * The database password
     *
     * @var string
     */
    private $db_pass;

    /**
     * The database name
     *
     * @var string
     */
    private $db_name;

    /**
     * The database host
     *
     * @var string
     */
    private $db_host;

    /**
     * The database port
     *
     * @var string
     */
    private $db_port;

    /**
     * Identifies the dump strategy local or s3
     *
     * @var string
     */
    private $dump_storage;

    /**
     * The path where we'll store the dumps required for local and s3
     *
     * @var string
     */
    private $dump_path;

    /**
     * The temporary path to extract the dump in case of s3.
     *
     * @var string
     */
    private $tmp_path;

    /**
     * The AWS credentials.
     *
     * @var array
     */
    private $aws;

    /**
     * Read all needed settings from the .env file and stores its values on class variables
     */
    public function __construct(){
        try{
            $conf = new Dotenv(__DIR__);
            $conf->load();
            $conf->required(['DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_PORT','DUMP_STORAGE','DUMP_PATH'])->notEmpty();
            $this->db_user = getenv('DB_USER');
            $this->db_pass = getenv('DB_PASS');
            $this->db_name = getenv('DB_NAME');
            $this->db_host = getenv('DB_HOST');
            $this->db_port = getenv('DB_PORT');
            $this->dump_path = getenv('DUMP_PATH');
            $this->dump_storage = getenv('DUMP_STORAGE');
            if($this->dump_storage == 's3'){
                $conf->required(['AWS_KEY','AWS_REGION','AWS_SECRET','AWS_BUCKET'])->notEmpty();
                $this->aws = [
                    'key'=>getenv('AWS_KEY'),
                    'region'=>getenv('AWS_REGION'),
                    'secret'=>getenv('AWS_SECRET'),
                    'bucket'=>getenv('AWS_BUCKET'),
                ];
            }
        }catch(\Dotenv\Exception\ValidationException $e){
            echo $e->getMessage()."\r\n";
            die;
        }
    }

    /**
     * Main function of the class.
     * Performs the dump and store it.
     *
     * @return void
     */
    public function dumpDb(){
        $dumper = new MySQLDumper\Mysqldump("mysql:host={$this->db_host};dbname={$this->db_name};port={$this->db_port}", $this->db_user, $this->db_pass,$this->getDumperSettings());
        $dump_file = $this->getDumpPath();
        $dumper->start($dump_file);
        if($this->dump_storage == 's3'){
            $this->moveToS3($dump_file);
        }
        $this->removeOldDumps();
    }

    /**
     * Move the dump file to a s3 bucket
     *
     * @param string $file_path
     * @return void
     */
    private function moveToS3($file_path){
        $key = @end((explode(DIRECTORY_SEPARATOR,$file_path)));
        $client = $this->getS3Client();
        $uploader = new MultipartUploader($client,$file_path,[
            'bucket'=>$this->aws['bucket'],
            'key'=>$this->dump_path.$key
        ]);

        do {
            try {
                $result = $uploader->upload();
            } catch (MultipartUploadException $e) {
                $uploader = new MultipartUploader($client, $file_path, [
                    'state' => $e->getState(),
                ]);
            }
        } while (!isset($result));
        unlink($file_path);
    }

    /**
     * Returns an instance of S3Client with the credentials
     *
     * @return resource \Aws\S3\S3Client
     */
    private function getS3Client(){
        return new S3Client([
            'version'=>'latest',
            'region'=>$this->aws['region'],
            'credentials'=> [
                'key'=>$this->aws['key'],
                'secret'=>$this->aws['secret']
            ]
        ]);
    }

    /**
     * Generates the dump path with the file name
     *
     * @return string
     */
    private function getDumpPath(){
        $return = "";
        if($this->dump_storage == 'local'){
            if(!is_dir($this->dump_path)){
                mkdir($this->dump_path,0777,true);
            }
            $return =  $this->dump_path;
        }
        if($this->dump_storage == 's3'){
            $this->tmp_path =sys_get_temp_dir();
            $return = $this->tmp_path.'/';
        }
        return $return.date('Y-m-d H_i_s').'.sql.gz';
    }

    /**
     * Removes the old dumps to avoid trash on s3 or local disk
     *
     * @return void
     */
    private function removeOldDumps(){
        if($this->dump_storage == 'local'){
            $files = glob("{$this->dump_path}/*");
            usort( $files, function( $a, $b ) { return filemtime($a) - filemtime($b); } );
            for($i=0;$i<(count($files)-3);$i++){
                unlink($files[$i]);
            }
        }
        if($this->dump_storage == 's3'){
            $client = $this->getS3Client();
            try {
                $results = $client->getPaginator('ListObjects', [
                    'Bucket' => $this->aws['bucket'],
                    'Prefix'=> $this->dump_path
                ]);
                foreach ($results as $result) {
                    for($i=0;$i<(count($result['Contents'])-3);$i++){
                        $client->deleteObject([
                            'Bucket' => $this->aws['bucket'],
                            'Key'    => $result['Contents'][$i]['Key']
                        ]);
                    }
                }
            } catch (S3Exception $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
    }

    /**
     * Defines the custom settings of db dump.
     *
     * see https://packagist.org/packages/ifsnop/mysqldump-php for more info
     */
    private function getDumperSettings(){
        return array(
            'compress' => MysqlDumper\MysqlDump::GZIP,
            'add-drop-table' => true,
            'add-locks' => true,
            'events' => true,
            'lock-tables' => true,
            'routines' => true,
        );
    }
}
$dumper = new DbDumper();
$dumper->dumpDb();