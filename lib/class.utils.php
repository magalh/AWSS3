<?php
namespace AWSS3;

require dirname(__DIR__, 1).'/SDK/aws-autoloader.php';

use Aws\S3\S3Client;  
use Aws\Exception\AwsException;
use Aws\Credentials\Credentials;

final class utils
{

    private $s3Client = null;

    public function __construct (){
        $this->mod = self::get_mod();
        $this->s3Client = null;
        $this->s3_bucket_name = $this->mod->GetPreference('s3_bucket_name');
        $this->s3_region = $this->mod->GetPreference('s3_region');
        $this->s3_uploads_secret = $this->mod->GetPreference('s3_uploads_secret');
        $this->s3_uploads_key = $this->mod->GetPreference('s3_uploads_key');
        $this->s3_bucket_url = $this->mod->GetPreference('s3_bucket_url');
    }

    public function is_aws_ready() {
        $mod = $this->mod;
        $smarty = cmsms()->GetSmarty();
		$data = array('s3_bucket_name','s3_region','s3_uploads_secret','s3_uploads_key');
		$error_message="";

        try {
            foreach ($data as $key)
            {
                $item = $mod->GetPreference($key);
                if($item=="" or $item==null)
                {
                    $error_message.=$mod->Lang($key).",";
                }
                if($error_message!="") {
                    throw new \Exception($error_message);

                }
            }
        } catch (\Exception $e) {
            //$smarty->assign('errormsg',$error_message.' cannot be null or empty.');
            return false;
        }

        $this->s3Client = self::getS3Client();
        if (!$this->s3Client){
            return false;
        }

        if(!$this->s3Client->doesBucketExist($mod->GetPreference('s3_bucket_name'))){
            $smarty->assign('aws_error_msg',$mod->GetPreference('s3_bucket_name')." does not exist");
            return false;
        }

        $this->check_iam_policy();

		
        return true;
		
	}

    private static function getS3Client(){

        $s3Client = new S3Client([
            'credentials' => self::get_credentials(),
            'region' => self::get_mod()->GetPreference('s3_region'),
            'version' => 'latest'
            ]);

        try {
            $buckets = $s3Client->listBuckets();
        } catch (AwsException $e) {
            $smarty = cmsms()->GetSmarty();
            $smarty->assign('aws_error_msg',$e->getAwsErrorMessage());
            return false;
        }

        return $s3Client;
    }

    public function upload_file($bucket_id,$file_name,$file_temp_src){
        try { 
            $result = $this->s3Client->putObject([ 
                'Bucket' => $bucket_id, 
                'Key'    => $file_name,
                'SourceFile' => $file_temp_src,
                'Tagging' => "Module=CMSMS::AWSS3",
                'ACL'    => 'public-read'
            ]); 
            $result_arr = $result->toArray(); 

            print_r($result);

        } catch (AwsException $e) { 
            $smarty = cmsms()->GetSmarty();
            $smarty->assign('aws_error_msg',$e->getAwsErrorMessage());
            echo $e->getCode() . " " .$e->getMessage();
            exit();
        } 
    }

    public function list_my_buckets(){
        $buckets = $this->s3Client->listBuckets();
        return $buckets['Buckets'];
    }

    public function bucketsExists($bucket_id){
       if($this->s3Client->doesBucketExist($bucket_id)){
        echo "bucket exists";
       } else {
        echo "bucket does not exist";
       }

    }

    public function removePath($link,$path){
        $newphrase = str_replace($path, "", $link);
        return $newphrase;
     }

    public function list_my_files(){
        $buckets = $this->s3Client->listBuckets();
        return $buckets['Buckets'];
    }

    public static function deleteObject($bucket,$keyname){
        $s3 = self::getS3Client();
        try
            {
                echo 'Attempting to delete ' . $keyname . '...' . PHP_EOL;

                $result = $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $keyname
                ]);

                if ($result['DeleteMarker'])
                {
                    echo $keyname . ' was deleted or does not exist.' . PHP_EOL;
                } else {
                    exit('Error: ' . $keyname . ' was not deleted.' . PHP_EOL);
                }
            }
            catch (AwsException $e) {
                exit('Error: ' . $e->getAwsErrorMessage() . PHP_EOL);
            }

    }

    public static function deleteObjectDir($bucket_id,$dir){
        $s3 = self::getS3Client();

/*        $dir = rtrim($dir, "/");
        $dir = ltrim($dir, "/");
        $dir = $dir . "/";*/
    
        $keys = $s3->listObjects([
            'Bucket' => $bucket_id,
            'Prefix' => $dir
        ]);

        // 3. Delete the objects.
        foreach ($keys['Contents'] as $key)
        {
            $s3->deleteObjects([
                'Bucket'  => $bucket_id,
                'Delete' => [
                    'Objects' => [
                        [
                            'Key' => $key['Key']
                        ]
                    ]
                ]
            ]);
        }
    
        return true;
    
    }
    
    private static function get_mod()
    {
        static $_mod;
        if( !$_mod ) $_mod = \cms_utils::get_module('AWSS3');
        return $_mod;
    }

    private static function get_credentials()
    {
        $mod = self::get_mod();
        $credentials = new Credentials($mod->GetPreference('s3_uploads_secret'), $mod->GetPreference('s3_uploads_key'));
        return $credentials;
    }

    private static function presignedUrl($bucket_id,$key,$s3Client)
    {
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $bucket_id,
            'Key' => $key
        ]);

        $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
        $presignedUrl = (string)$request->getUri();
        return $presignedUrl;
    }

    public static function formatBytes($bytes) {
        if ($bytes > 0) {
            $i = floor(log($bytes) / log(1024));
            $sizes = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
            return sprintf('%.02F', round($bytes / pow(1024, $i),1)) * 1 . ' ' . @$sizes[$i];
        } else {
            return 0;
        }
    }


    public static function get_file_list($bucket_id,$prefix=null)
    {

        $mod = \cms_utils::get_module('AWSS3');
        $filemod = \cms_utils::get_module('FileManager');
        $showhiddenfiles=$filemod->GetPreference('showhiddenfiles','1');
        $result=array();
        $config = \cms_config::get_instance();

        $s3Client = self::getS3Client();

        try {
            $contents = $s3Client->listObjectsV2([
                'Bucket' => $bucket_id,
                'Prefix' => $prefix,
                'Delimiter' => '/'
            ]);

        } catch (AwsException $e) {
            // Handle the error
            if($e->getStatusCode() == 404){
                $error_message = $bucket_id." ".$e->getAwsErrorMessage();
            } else {
                $error_message = $e->getMessage();
            }

            $smarty = cmsms()->GetSmarty();
            $smarty->assign('aws_error_msg',$error_message);
        }

        //print_r($contents);

        if(isset($prefix) && $prefix!=''){

            $info=array();
            $info['name']= '..';
            $info['dir'] = true;
            $info['mime'] = 'directory';
            $info['ext']='';
            $result[]=$info;

        }

        //Directories
        foreach ($contents['CommonPrefixes'] as $dir) {

            $info=array();
            $info['name']=$dir['Prefix'];
            $info['dir'] = true;
            $info['mime'] = 'directory';
            $info['ext']='';

            $result[]=$info;

        }
        //Files 
        foreach ($contents['Contents'] as $content) {

            $cmd = $s3Client->headObject([
                'Bucket' => $bucket_id,
                'Key' => $content['Key']
            ]);

            $info=array();
            $info['name']=$content['Key'];
            $info['dir'] = FALSE;
            $info['image'] = FALSE;
            $info['archive'] = FALSE;
            $info['mime'] = $cmd['ContentType'];

                if (is_dir($fullname)) {
                    $info['dir']=true;
                    $info['ext']='';
                } else {
                    $info['size']=self::formatBytes($content['Size']);
                    $info['date']=strtotime( $content['LastModified'] );
                    $info['url']=self::presignedUrl($bucket_id,$content['Key'],$s3Client);
                    //$info['url']= $s3Client->getObjectUrl($bucket_id, $content['Key']);;
                    //$info['url'] = str_replace('\\','/',$info['url']); // windoze both sucks, and blows.
                    $explodedfile=explode('.', $content['Key']); $info['ext']=array_pop($explodedfile);
                }
            
            // test for archive
            $info['archive'] = '';
            // test for image
            $info['image'] = '';
            $info['fileowner']='N/A';
            $info['writable']='';

            $result[]=$info;


            }

        return $result;
    }

    public static function is_file_acceptable( $file_type )
  {
      $file_type = strtolower($file_type);
      $allowTypes = array('pdf','doc','docx','xls','xlsx','jpg','png','jpeg','gif'); 
      if(!in_array($file_type, $allowTypes)) return FALSE;
        
      return TRUE;
  }

  public function check_iam_policy() {
        $smarty = cmsms()->GetSmarty();
        try {
            $resp = $this->s3Client->getBucketPolicy([
                'Bucket' => $this->s3_bucket_name
            ]);
            $smarty->assign('aws_error_msg',"Succeed in receiving bucket policy:");

        } catch (AwsException $e) {
            // Display error message
            if($e->getStatusCode() == 404){
                //$this->put_iam_policy();
                $smarty->assign('aws_error_msg',$this->s3_bucket_name." ".$e->getAwsErrorMessage());
            }
            
        }

    }

    private function put_iam_policy() {

        $policy = $this->get_iam_policy();
        $smarty = cmsms()->GetSmarty();

        try {
            $resp = $this->s3Client->putBucketPolicy([
                'Bucket' => $this->s3_bucket_name,
                'Policy' => $policy,
            ]);
            echo "Succeed in put a policy on bucket: " . $this->s3_bucket_name . "\n<br>";
        } catch (AwsException $e) {
            // Display error message
            $smarty->assign('aws_error_msg',$this->s3_bucket_name." ".$e->getAwsErrorMessage());

        }

    }

    private function get_iam_policy() : string {

        $bucket_id = $this->s3_bucket_name;
		$bucket = strtok( $bucket_id, '/' );

		$path = null;

		if ( strpos( $bucket_id, '/' ) ) {
			$path = str_replace( strtok( $bucket_id, '/' ) . '/', '', $bucket_id );
		}

		return '{
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Sid": "BasicAccess",
                    "Principal": "*",
                    "Effect": "Allow",
                    "Action": [
                        "s3:AbortMultipartUpload",
                        "s3:DeleteObject",
                        "s3:GetObject",
                        "s3:GetObjectAcl",
                        "s3:PutObject",
                        "s3:PutObjectAcl"
                    ],
                    "Resource": [
                        "arn:aws:s3:::'.$bucket_id.'/*"
                    ]
                },
                {
                    "Sid": "AllowRootAndHomeListingOfBucket",
                    "Principal": "*",
                    "Effect": "Allow",
                    "Action": [
                        "s3:GetBucketAcl",
                        "s3:GetBucketLocation",
                        "s3:GetBucketPolicy",
                        "s3:ListBucket"
                    ],
                    "Resource": ["arn:aws:s3:::' . $bucket_id . '"]
                }
            ]
        }';

        
	}

  


}
?>