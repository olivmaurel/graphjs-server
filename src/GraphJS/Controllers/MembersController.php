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
use PhoNetworksAutogenerated\UserOut\Follow;
use Pho\Lib\Graph\ID;


/**
 * Takes care of Members
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class MembersController extends AbstractController
{
    /**
     * Get Members
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * @param Kernel   $this->kernel
     * 
     * @return void
     */
    public function getMembers(ServerRequestInterface $request, ResponseInterface $response)
    {
        $isModerated = $this->isMembershipModerated();
        $verificationRequired = $this->isVerificationRequired();
        $nodes = $this->kernel->graph()->members();
        $members = [];
        foreach($nodes as $node) {
            if($node instanceof User) {
                $is_editor = (
                    (isset($node->attributes()->IsEditor) && (bool) $node->attributes()->IsEditor)
                    ||
                    ($this->kernel->founder()->id()->equals($node->id()))
                );
                if(
                    (!$isModerated||!$node->attributes()->Pending)
                    &&
                    (!$verificationRequired||!$node->attributes()->PendingVerification)
                    )
                    $members[(string) $node->id()] = [
                        "id" => (string) $node->id(),
                        "username" => (string) $node->getUsername(),
                        "email" => (string) $node->getEmail(),
                        "avatar" => (string) $node->getAvatar(),
                        "is_editor" => intval($is_editor)
                    ];
            }
        }
        $members_count = count($members);
        $members = $this->paginate($members, $request->getQueryParams(), 20);
        return $this->succeed($response, [
            "members" => $members,
            "total"   => $members_count
        ]);
    }
 
    public function getFollowers(ServerRequestInterface $request, ResponseInterface $response)
    {
     $data = $request->getQueryParams();
     if(!isset($data["id"])||!preg_match("/^[0-9a-fA-F][0-9a-fA-F]{30}[0-9a-fA-F]$/", $data["id"])) {
       if(is_null($id = Session::depend($request))) {
            return $this->fail($response, "Either session required or a valid ID must be entered.");
        }
     }
        else {
         $id = $data["id"];
        }
        
        $i = $this->kernel->gs()->node($id);
        $incoming_follows = \iterator_to_array($i->edges()->in(Follow::class));
        $followers = [];
        foreach($incoming_follows as $follow) {
            $follower = $follow->tail();
            $followers[(string) $follower->id()] = array_change_key_case(
                array_filter(
                    $follower->attributes()->toArray(), 
                    function (string $key): bool {
                        return strtolower($key) != "password";
                    },
                    ARRAY_FILTER_USE_KEY
                ), CASE_LOWER
            );
        }
        return $this->succeed($response, ["followers"=>$followers]);
    }

    public function getFollowing(ServerRequestInterface $request, ResponseInterface $response)
    {
        $data = $request->getQueryParams();
     if(!isset($data["id"])||!preg_match("/^[0-9a-fA-F][0-9a-fA-F]{30}[0-9a-fA-F]$/", $data["id"])) {
       if(is_null($id = Session::depend($request))) {
            return $this->fail($response, "Either session required or a valid ID must be entered.");
        }
     }
        else {
         $id = $data["id"];
        }
        $i = $this->kernel->gs()->node($id);
        $outgoing_follows = \iterator_to_array($i->edges()->out(Follow::class));
        $following = [];
        foreach($outgoing_follows as $follow) {
            $f = $follow->head();
            $following[(string) $f->id()] = array_change_key_case(
                array_filter(
                    $f->attributes()->toArray(), 
                    function (string $key): bool {
                        return strtolower($key) != "password";
                    },
                    ARRAY_FILTER_USE_KEY
                ), CASE_LOWER
            );
        }
        return $this->succeed($response, ["following"=>$following]);
    }

    /**
     * Follow someone
     *
     * id
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * @param Kernel   $this->kernel
     * 
     * @return void
     */
    public function follow(ServerRequestInterface $request, ResponseInterface $response)
    {
        if(is_null($id = Session::depend($request))) {
            return $this->fail($response, "Session required");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Valid user ID required.");
        }
        if(!preg_match("/^[0-9a-fA-F][0-9a-fA-F]{30}[0-9a-fA-F]$/", $data["id"])) {
            return $this->fail($response, "Invalid ID");
        }
        if($data["id"]==$id) {
            return $this->fail($response, "Follower and followee can't be the same");
        }
        $i = $this->kernel->gs()->node($id);
        $followee = $this->kernel->gs()->node($data["id"]);
        if(!$i instanceof User) {
            return $this->fail($response, "Session owner not a User");
        }
        if(!$followee instanceof User) {
            return $this->fail($response, "Followee not a User");
        }
        $i->follow($followee);
        return $this->succeed($response);
    }

    /**
     * Unfollow someone
     *
     * id
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface $response
     * 
     * @return void
     */
    public function unfollow(ServerRequestInterface $request, ResponseInterface $response)
    {
        if(is_null($id = Session::depend($request))) {
            return $this->fail($response, "Session required");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Valid user ID required.");
        }
        if(!preg_match("/^[0-9a-fA-F][0-9a-fA-F]{30}[0-9a-fA-F]$/", $data["id"])) {
            return $this->fail($response, "Invalid ID");
        }
        $i = $this->kernel->gs()->node($id);
        $followee = $this->kernel->gs()->node($data["id"]);
        if(!$i instanceof User) {
            return $this->fail($response, "Session owner not a User");
        }
        if(!$followee instanceof User) {
            return $this->fail($response, "Followee not a User");
        }
        $follow_edges = $i->edges()->to($followee->id(), Follow::class);
        if(count($follow_edges)!=1) {
            return $this->fail($response, "No follow edge found: ".count($follow_edges));
        }
        //eval(\Psy\sh());
        $follow_edges->current()->destroy();
        return $this->succeed($response);
    }

}
