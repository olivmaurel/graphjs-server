<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphJS\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use GraphJS\Session;
use Pho\Kernel\Kernel;
use PhoNetworksAutogenerated\User;
use Mailgun\Mailgun;
use GraphJS\Crypto;

 /**
 * Takes care of Authentication
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class AuthenticationController extends AbstractController
{
 
 const PASSWORD_RECOVERY_EXPIRY = 15*60;

    public function tokenSignup(ServerRequestInterface $request, ResponseInterface $response)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'token' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Valid username, email are required.");
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            return $this->fail($response, "Invalid username");
        }
        try {
            $username = Crypto::decrypt($data["token"], $key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        if($username!=$data["username"]) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8));
        error_log("sign up password is ".$password);
        return $this->actualSignup($request,  $response, $username, $data["email"], $password);
    }

    /**
     * Sign Up
     * 
     * [username, email, password]
     * 
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * 
     * @return void
     */
    public function signup(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Valid username, email and password required.");
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            return $this->fail($response, "Invalid username");
        }
        if(!preg_match("/[0-9A-Za-z!@#$%_]{5,15}/", $data["password"])) {
            return $this->fail($response, "Invalid password");
        }
        return $this->actualSignup( $request,  $response, $data["username"], $data["email"], $data["password"]);
    }

    protected function actualSignup(ServerRequestInterface $request, ResponseInterface $response, string $username, string $email, string $password)
    {
        //$verificationRequired = $this->isVerificationRequired($this->kernel);
        $data = $request->getQueryParams();
        $extra_reqs_to_validate = [];
        $reqs = $this->kernel->graph()->attributes()->toArray();
        //error_log("about to enter custom_fields loop: ".print_r($reqs, true));
        error_log("about to enter custom_fields loop");
        error_log("about to enter custom_fields loop: ".count($reqs));
        error_log("about to enter custom_fields loop: ".print_r(array_keys($reqs), true));
        for($i=1;$i<4;$i++) {
            if(
                isset($reqs["CustomField{$i}Must"])&&isset($reqs["CustomField{$i}"])
                &&$reqs["CustomField{$i}Must"]
                &&!empty($reqs["CustomField{$i}"])
            ) {
                $field = "custom_field{$i}"; // $reqs["CustomField{$i}"];
                $extra_reqs_to_validate[$field] = 'required';
            }
        }
        error_log("out of custom_fields loop");
        $validation = $this->validator->validate($data, $extra_reqs_to_validate);
        if($validation->fails()) {
            return $this->fail($response, "Valid ".addslashes(implode(", ", $extra_reqs_to_validate)). " required.");
        }
        
        $result = $this->kernel->index()->query(
            "MATCH (n:user) WHERE n.Username= {username} OR n.Email = {email} RETURN n",
            [ 
                "username" => $username,
                "email"    => $email
            ]
        );
        error_log(print_r($result, true));
        $duplicate = (count($result->results()) >= 1);
        if($duplicate) {
            error_log("duplicate!!! ");
            return $this->fail($response, "Duplicate user");
        }

        try {
            $new_user = new User(
                $this->kernel, $this->kernel->graph(), $username, $email, $password
            );
        } catch(\Exception $e) {
            return $this->fail($response, $e->getMessage());
        }


        for($i=1;$i<4;$i++) {
            if(isset($reqs["CustomField{$i}"])&&!empty($reqs["CustomField{$i}"])&&isset($data["custom_field{$i}"])&&!empty($data["custom_field{$i}"])) {
                $_ = "setCustomField{$i}";
                $new_user->$_($data["custom_field{$i}"]);
            }
        }

        $moderation = $this->isMembershipModerated();
        if($moderation)
            $new_user->setPending(true);

        $verification = $this->isVerificationRequired();
        if($verification) {
            $pin = rand(100000, 999999);
            $new_user->setPendingVerification($pin);
                $mgClient = new Mailgun(getenv("MAILGUN_KEY")); 
                $mgClient->sendMessage(getenv("MAILGUN_DOMAIN"),
                array('from'    => 'GraphJS <postmaster@client.graphjs.com>',
                        'to'      => $data["email"],
                        'subject' => 'Please Verify',
                        'text'    => 'Please enter this 6 digit passcode to verify your email: '.$pin)
                );
        }

        Session::begin($response, (string) $new_user->id());

        return $this->succeed(
            $response, [
                "id" => (string) $new_user->id(),
                "pending_moderation"=>$moderation,
                "pending_verification"=>$verification
            ]
        );
    }

    /**
     * Log In
     * 
     * [username, password]
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * 
     * @return void
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'password' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Username and password fields are required.");
        }

        return $this->actualLogin($request, $response, $data["username"], $data["password"]);

    }

    /**
     * Log In Via Token
     * 
     * [token]
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * 
     * @return void
     */
    public function tokenLogin(ServerRequestInterface $request, ResponseInterface $response)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'token' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Token field is required.");
        }
        try {
            $username = Crypto::decrypt($data["token"], $key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8)); // substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8);
        //error_log("username is: ".$username."\npassword is: ".$password);
        
        return $this->actualLogin($request, $response, $username, $password);
        
    }

    protected function actualLoginViaEmail(string $email, string $password): ?array
    {
        $result = $this->kernel->index()->query(
            "MATCH (n:user {Email: {email}, Password: {password}}) RETURN n",
            [ 
                "email" => $email,
                "password" => md5($password)
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            return null;
        }
        return $result->results()[0];
    }

    protected function actualLogin(ServerRequestInterface $request, ResponseInterface $response, string $username, string $password)
    {
        
        $result = $this->kernel->index()->query(
            "MATCH (n:user {Username: {username}, Password: {password}}) RETURN n",
            [ 
                "username" => $username,
                "password" => md5($password)
            ]
        );
        //error_log(print_r($result, true));
        $success = (count($result->results()) >= 1);
        if(!$success) {
            error_log("try with email!!! ");
            $user = $this->actualLoginViaEmail($username, $password);
            if(is_null($user)) {
                error_log("failing!!! ");
                return $this->fail($response, "Information don't match records");
            }
        }
        else {
            error_log("is a  success");
            $user = $result->results()[0];
        }
        
        error_log(print_r($user));
        error_log(intval($this->isMembershipModerated()));
        error_log("Done");

        if($this->isMembershipModerated() && $user["n.Pending"]) {
            return $this->fail($response, "Pending membership");
        }

        if($this->isVerificationRequired() && $user["n.PendingVerification"]) {
            return $this->fail($response, "You have not verified your email yet");
        }

        Session::begin($response, $user["n.udid"]);

        return $this->succeed(
            $response, [
                "id" => $user["n.udid"],
                "pending" => $user["n.Pending"]
            ]
        );
    }

    /**
     * Log Out
     *
     * @param  ServerRequestInterface  $request
     * @param  ResponseInterface $response
     * @return void
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response) 
    {
        Session::destroy($response);
        return $this->succeed($response);
    }

    /**
     * Who Am I?
     * 
     * @param  ServerRequestInterface  $request
     * @param  ResponseInterface $response
     * @return void
     */
    public function whoami(ServerRequestInterface $request, ResponseInterface $response)
    {
        if(is_null($id = Session::depend($request))) {
            return $this->failSession($response);
        }
        try {
            $i = $this->kernel->gs()->node($id);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid user");
        }
        
        return $this->succeed($response, [
                "id" => $id, 
                "admin" => (bool) ($id==$this->kernel->founder()->id()->toString()),
                "username" => (string) $i->getUsername(),
                "editor" => ( 
                    (($id==$this->kernel->founder()->id()->toString())) 
                    || 
                    (isset($i->attributes()->IsEditor) && (bool) $i->getIsEditor())
                ),
                "pending" => (
                    (isset($i->attributes()->Pending) && (bool) $i->getPending())
                )
            ]
        );
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Valid email required.");
        }


        $result = $this->kernel->index()->query(
            "MATCH (n:user {Email: {email}}) RETURN n",
            [ 
                "email" => $data["email"]
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            return $this->succeed($response); // because we don't want to let them know our userbase
        }


        // check if email exists ?
        $pin = mt_rand(100000, 999999);
        if($this->_isRedisPasswordReminder()) {
            $this->kernel->database()->set("password-reminder-".md5($data["email"]), $pin);
            $this->kernel->database()->expire("password-reminder-".md5($data["email"]), self::PASSWORD_RECOVERY_EXPIRY);
        }
        else{
            file_put_contents(getenv("PASSWORD_REMINDER").md5($data["email"]), "{$pin}:".time()."\n", LOCK_EX);
        }
        $mgClient = new Mailgun(getenv("MAILGUN_KEY")); 
        $mgClient->sendMessage(getenv("MAILGUN_DOMAIN"),
        array('from'    => 'GraphJS <postmaster@client.graphjs.com>',
                'to'      => $data["email"],
                'subject' => 'Password Reminder',
                'text'    => 'You may enter this 6 digit passcode: '.$pin)
        );
        return $this->succeed($response);
    }
 
 protected function _isRedisPasswordReminder(): bool
 {
      $redis_password_reminder = getenv("PASSWORD_REMINDER_ON_REDIS");
      error_log("password reminder is ".$redis_password_reminder);
      return($redis_password_reminder===1||$redis_password_reminder==="1"||$redis_password_reminder==="on");
 }
 
 public function verifyEmailCode(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id'   => 'required',
            'code' => 'required'
        ]);
        if($validation->fails()
        ||!preg_match("/^[0-9]{6}$/", $data["code"])
        ||!preg_match("/^[0-9a-fA-F]{32}$/", $data["id"])
        ) {
            return $this->fail($response, "Valid code, ID are required.");
        }
        $id =(string) $data["id"];
        try {
            $i = $this->kernel->gs()->node($id);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid user");
        }

        $code_expected = (int) $i->getPendingVerification();
        if($code_expected==0)
            return $this->fail($response, "Invalid code");

        $data["code"] = (int) $data["code"];
        if($code_expected!=$data["code"]) {
            return $this->fail($response, "Invalid code");
        }

        $i->setPendingVerification(0);

        $data["id"] = strtolower($data["id"]);
        
        Session::begin($response, $data['id']);

        return $this->succeed($response, [
            "id"=>$data["id"],
            "username"=>$i->getUsername()
        ]);
    }

    public function verifyReset(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
            'code' => 'required',
        ]);
        if($validation->fails()||!preg_match("/^[0-9]{6}$/", $data["code"])) {
            return $this->fail($response, "Valid email and code required.");
        }
        $pins = explode(":", trim(file_get_contents(getenv("PASSWORD_REMINDER").md5($data["email"]))));
        if($this->_isRedisPasswordReminder()) {
            $pins = [];
            $pins[0] = $this->kernel->database()->get("password-reminder-".md5($data["email"]));
        }
        else{
            $pins = explode(":", trim(file_get_contents(getenv("PASSWORD_REMINDER").md5($data["email"]))));
        }
        //error_log(print_r($pins, true));
        if($pins[0]!=$data["code"]) {
            return $this->fail($response, "Code does not match.");
        }
            
        //if((int) $pins[1]<time()-7*60) {
        if(!$this->_isRedisPasswordReminder() && (int) $pins[1]<time()-self::PASSWORD_RECOVERY_EXPIRY) {
            return $this->fail($response, "Expired.");
        }
         
        $result = $this->kernel->index()->query(
            "MATCH (n:user {Email: {email}}) RETURN n",
            [ 
                "email" => $data["email"]
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            return $this->fail($response, "This user is not registered");
        }
        $user = $result->results()[0];
        Session::begin($response, $user["n.udid"]);
        return $this->succeed($response);
    }

}
