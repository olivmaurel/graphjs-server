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

use CapMousse\ReactRestify\Http\Request;
use CapMousse\ReactRestify\Http\Response;
use CapMousse\ReactRestify\Http\Session;
use Pho\Kernel\Kernel;
use PhoNetworksAutogenerated\User;
use Rakit\Validation\Validator;



/**
 * An abstract controller that includes common operations in GraphJS
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
abstract class AbstractController extends   \Pho\Server\Rest\Controllers\AbstractController
{
    protected $validator;

    public function __construct()
    {
        $this->validator = new Validator();
    }

    private static function utf8ize($mixed) {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } else if (is_string ($mixed)) {
            // return \mb_convert_encoding(\utf8_encode($mixed), 'HTML-ENTITIES', 'UTF-8');
            return $mixed; // \utf8_encode($mixed);
        }
        return $mixed;
    }

    /**
     * Utility function to sort associative arrays by value
     * 
     * Source: https://www.texelate.co.uk/blog/sort-associative-array-by-value-and-keep-keys-with-php
     *
     * @param array $arrayToSort
     * @param string $sortKey
     * @param boolean $isAsc
     * @param boolean $keepKeys
     * @return array
     */
    protected static function sortAssocArrayByValue(array $arrayToSort, string $sortKey, bool $isAsc = true, bool $keepKeys = false): array 
    {
        if ($isAsc === true) {
            $sort = SORT_ASC;
        } else {
            $sort = SORT_DESC;
        }
    
        $array 	= [];
        $data	= [];
    
        // The keys are preserved by making them strings
        foreach ($arrayToSort as $key => $value) {
            if ($keepKeys === true) {
               $k = '_' . $key;
            } else {
                $k = $key;   
            }
    
            $data[$k]	= $value;
            $array[$k] 	= $value[$sortKey];
        }
    
        // This sorts the data based on $array
        array_multisort($array, $sort, $data);
    
        // If the keys are not being kept then the work is done
        if ($keepKeys === false) {
           return $data; 
        }

        // To keep the keys the new array overwrites the old one and the numerical keys are restored
        $arrayToSort = [];
    
        foreach ($data as $key => $value) {
    
            $arrayToSort[ltrim($key, '_')] = $value;
    
        }
        return $arrayToSort;
    }

    protected function succeed(Response $response, array $data = []): void
    {
        $data = self::utf8ize($data);
        //error_log("will succeed with: ".print_r($data, true));
        $final_data = array_merge(
            ["success"=>true], 
            $data
        );
        //error_log("~~ json encoded output is: ".json_encode($final_data));
        //error_log("json error: ".json_last_error());
        $content_length = mb_strlen(json_encode($final_data),'utf8');
        //error_log("~~ content-length: ".$content_length);
        $method = $this->getWriteMethod();
        $response
            ->addHeader("Access-Control-Allow-Credentials", "true")
            ->addHeader("Content-Length", $content_length)
            ->$method($final_data)
            ->end();
    }

    protected function fail(Response $response, string $message = ""): void
    {
        $method = $this->getWriteMethod();
        $response
                    ->addHeader("Access-Control-Allow-Credentials", "true")
                    ->$method([
                        "success" => false,
                        "reason"   => $message
                    ])
                    ->end();
    }
    
    /**
     * Makes sure the method is dependent on session availability
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param variadic $ignore
     * 
     * @return int 0 if session does not exists, user ID otherwise.
     */
    protected function dependOnSession(Request $request, Response $response, Session $session, ...$ignore): ?string
    {
        $id = $session->get($request, "id");
        if(is_null($id)) {
            $this->fail($response->addHeader("Access-Control-Allow-Credentials", "true"), "No active session");
            return null;
        }
        return $id;
    }

    protected function handleException(Response $response, /*\Exception|\Error*/ $e): void
    {
        $this->fail($response, sprintf(
            "An exception occurred: %s",
            $e->getMessage()
        ));
    }

    public function setExceptionHandler(Response $response): self
    {
        @set_exception_handler(function(/*\Exception|\Error*/ $e) use ($response) {
            $this->handleException($response, $e);
        });
        return $this;
    }

    protected function checkPasswordFormat(string $password): bool
    {
        return preg_match("/[0-9A-Za-z!@#$%_]{5,15}/", $password);
    }
}
