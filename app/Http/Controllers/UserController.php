<?php

namespace App\Http\Controllers;

//use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Validator;
use Illuminate\Database\Query;
class userController extends Controller
{

/*
*错误码
*10000 success
*10001 singup_fail
*10002 login_fail
*11000 email_exist
*11001 phone_exist
*20000 laravel验证器返回错误
*20001 token_verify_fail
*20002 token_expired
*/


  /**
   * 为指定用户显示详情
   *
   * @param  int $id
   * @return Response
   * @author chuanyu
   */
  public function info(){

  }

  /**
   * 注册方法
   * @access  public
   * @param   string   $phoneOrEmail  手机或邮箱
   * @param   int      $password      密码
   * @param   int      $verifycode    验证码
   * @return  json
   */
  public function signup(Request $request){
      $input = $request->all();
      $validator = Validator::make($input, [
          'password' => 'required|max:255|min:6',
          //'verifycode' => 'required', //手机验证码逻辑先放着
      ]);
      if ($validator->fails()) {
          //redirect('user/signup')->withErrors($validator)->withInput();
          return response()->json(['resCode'=>20000,'resMsg'=>implode('',$validator->errors()->all())]);

      }

      $is_email = $this->is_email($input['phoneOrEmail'])?$input['phoneOrEmail']:'';
      $is_phone = $this->is_mobile_phone($input['phoneOrEmail'])?$input['phoneOrEmail']:'';
      if($is_email){
          $is_email = DB::table('user')->where('email',$is_email)->doesntExist();
          if(!$is_email){
               return response()->json([
                  'resCode'=>11000,
                  'resmsg' =>'email_exist'
              ]);
          }
      }else{
          $is_phone = DB::table('user')->where('phone',$is_phone)->doesntExist();
          if(!$is_phone){
               return response()->json([
                  'code'=>11001,
                  'msg' =>'phone_exist'
              ]);
          }
      }
      $gen_userid = substr(md5(time().mt_rand(1,10000)),0,20);
      if(DB::table('user')->where('userid',$gen_userid)->exists()){
        $gen_userid = substr(md5(time().mt_rand(1,10000)),0,16);
      }
      $add['userid']   = $gen_userid;
      $add['username'] = $gen_userid;
      $add['email']    = $is_email?$input['phoneOrEmail']:$gen_userid;
      $add['phone']    = $is_phone?$input['phoneOrEmail']:$gen_userid;
      $add['password'] = Hash::make($input['password']);//md5(Crypt::encryptString($input['password']));
      $add['reg_time'] = time();
      $add['reg_ip']   = $_SERVER['REMOTE_ADDR'];
      $add['is_new']   = 1;
      $add['token']    = strval(md5(Hash::make(microtime(true).mt_rand())));
      $add['token_modify_time'] = time();
      $res = DB::table('user')->insert($add);
      if($res){
          $row['resCode'] = 10000;
          $row['resMsg']  = "success";
          unset($add['password']);
          $row['data']    = $add;
          return response()->json($row);            
      }else{
          $row['resCode'] = 10001;
          $row['resMsg']  = "signup_fail";
          $row['data']    = [];
          return response()->json($row);
      }

  }

  /*
   * @access  public
   * @param   string   $phoneOrName  手机或用户名
   * @param   string   $userid       用户ID
   * @param   int      $password     密码
   * @param   string   $token        接口令牌
   * @return  json
  */

  public function login(Request $request){
      $input = $request->all();
      $validator = Validator::make($input, [
          'phoneOrName' => 'required|max:11',  
          'password'    => 'required|max:255|min:6',
          'token'       => 'required|max:32|min:32',
          //'userid'      => 'required',
      ]);
      if ($validator->fails()) {
          return response()->json(['resCode'=>20000,'resMsg'=>implode('',$validator->errors()->all())]);
      }
      $this->auth_token('user',$input['token']); //检验令牌函数

      $is_phone = $this->is_mobile_phone($input['phoneOrName'])?$input['phoneOrName']:'';
  //DB::enableQueryLog();
      if($is_phone){ //如果是手机
      	$pwd = DB::table('user')->whereRaw('phone = ?  and token = ?',[$input['phoneOrName'],$input['token']])->value('password');
      }else{
      	$pwd = DB::table('user')->whereRaw('username = ?  and token = ?',[$input['phoneOrName'],$input['token']])->value('password');
      }
//dump(DB::getQueryLog());

      if($pwd){
        $is_pass = Hash::check($input['password'],$pwd);
      }
      if($is_pass){
          DB::transaction(function ()use($is_phone,$input) {
            $update['login_time'] = time();
            $update['login_ip']   = $_SERVER['REMOTE_ADDR'];
            $update['is_new']     = 0;
            if($is_phone){
                DB::table('user')->where('phone',$input['phoneOrName'])->update($update);
            }else{
                DB::table('user')->where('username',$input['phoneOrName'])->update($update);
            }
          },5);
      		return response()->json([
      			'resCode'=>10000,
      			'resMsg' =>'success'
      		]);
      }else{
          return response()->json([
      			'resCode'=>10002,
      			'resMsg' =>'login_fail'
      		]);
      }  
      
     
  }



  /*-------------内部函数-----------------*/
  /*过滤非法字段函数*/
  function replaceSpecialChar($strParam){
      $regex = "/\/|\~|\!|\@|\#|\\$|\%|\^|\&|\*|\(|\)|\_|\+|\{|\}|\:|\<|\>|\?|\[|\]|\,|\.|\/|\;|\'|\`|\-|\=|\\\|\|/";
      return preg_replace($regex,"",$strParam);
  }

  /**
   * 验证输入的邮件地址是否合法
   * @access  public
   * @param   string      $email      需要验证的邮件地址
   * @return bool
   */
  function is_email($email)
  {
      $email = $this->replaceSpecialChar($email);
      $chars = "/^([a-z0-9+_]|\\-|\\.)+@(([a-z0-9_]|\\-)+\\.)+[a-z]{2,6}\$/i";
      if (strpos($email, '@') !== false && strpos($email, '.') !== false)
      {
          if (preg_match($chars, $email))
      {
          return true;
      }else{
          return false;
      }
      }else{
      return false;
      }
  }

  /**
   * 验证输入的手机号码是否合法
   * @access public
   * @param string $mobile_phone
   * 需要验证的手机号码
   * @return bool
   */
  function is_mobile_phone ($mobile_phone)
  {
      $mobile_phone = $this->replaceSpecialChar($mobile_phone);
      $chars = "/^13[0-9]{1}[0-9]{8}$|15[0-9]{1}[0-9]{8}$|18[0-9]{1}[0-9]{8}$|17[0-9]{1}[0-9]{8}$/";
      if(preg_match($chars, $mobile_phone))
      {
      return true;
      }
      return false;
  }

  /*
  * 验证token的有效性（是否对得上或是否过期），错误返回对应错误码和信息
  * 
  */
  function auth_token($table = '',$token = '',$userid = '',$expire = 3600*24){
      if($userid){
          $res = DB::table($table)->where('userid',$userid)->value('token_modify_time');
          if(!$res){
             return response()->json(['resCode'=>20001,'resMsg'=>'token_verify_fail']);
          }
      }else{
          $res = DB::table($table)->where('token',$token)->value('token_modify_time');
          if(!$res){
             return response()->json(['resCode'=>20001,'resMsg'=>'token_verify_fail']);
          }
      }

      if($res<(time()-$expire)){
          return response()->json(['resCode'=>20002,'resMsg'=>'token_expired']);
      }else{
          return true;
      }
  }

  /*PHP获取当前用户真实IP的方法*/
  /*function getIp(){ 
      $onlineip=''; 
      if(getenv('HTTP_CLIENT_IP')&&strcasecmp(getenv('HTTP_CLIENT_IP'),'unknown')){ 
          $onlineip=getenv('HTTP_CLIENT_IP'); 
      } elseif(getenv('HTTP_X_FORWARDED_FOR')&&strcasecmp(getenv('HTTP_X_FORWARDED_FOR'),'unknown')){ 
          $onlineip=getenv('HTTP_X_FORWARDED_FOR'); 
      } elseif(getenv('REMOTE_ADDR')&&strcasecmp(getenv('REMOTE_ADDR'),'unknown')){ 
          $onlineip=getenv('REMOTE_ADDR'); 
      } elseif(isset($_SERVER['REMOTE_ADDR'])&&$_SERVER['REMOTE_ADDR']&&strcasecmp($_SERVER['REMOTE_ADDR'],'unknown')){ 
          $onlineip=$_SERVER['REMOTE_ADDR']; 
      } 
      return $onlineip; 
  } */

}