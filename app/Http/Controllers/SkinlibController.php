<?php

namespace App\Http\Controllers;

use View;
use Utils;
use Option;
use Storage;
use Session;
use App\Models\User;
use App\Models\Texture;
use Illuminate\Http\Request;
use App\Exceptions\PrettyPageException;

class SkinlibController extends Controller
{
    private $user = null;

    public function __construct()
    {
        $this->user = Session::has('uid') ? new User(session('uid')) : null;
    }

    public function index(Request $request)
    {
        $filter  = $request->input('filter', 'skin');
        $sort    = $request->input('sort', 'time');
        $uid     = $request->input('uid', 0);
        $page    = $request->input('page', 1);

        $sort_by = ($sort == "time") ? "upload_at" : $sort;

        if ($filter == "skin") {
            $textures = Texture::where(function($query) {
                $query->where('type', '=', 'steve')
                      ->orWhere('type', '=', 'alex');
            })->orderBy($sort_by, 'desc');

        } elseif ($filter == "user") {
            $textures = Texture::where('uploader', $uid)->orderBy($sort_by, 'desc');

        } else {
            $textures = Texture::where('type', $filter)->orderBy($sort_by, 'desc');
        }

        if (!is_null($this->user)) {
            // show private textures when show uploaded textures of current user
            if ($uid != $this->user->uid && !$this->user->is_admin)
                $textures = $textures->where('public', '1');
        } else {
            $textures = $textures->where('public', '1');
        }

        $total_pages = ceil($textures->count() / 20);

        $textures = $textures->skip(($page - 1) * 20)->take(20)->get();

        return view('skinlib.index')->with('user', $this->user)
                                    ->with('sort', $sort)
                                    ->with('filter', $filter)
                                    ->with('textures', $textures)
                                    ->with('page', $page)
                                    ->with('total_pages', $total_pages);
    }

    public function search(Request $request)
    {
        $q      = $request->input('q', '');
        $filter = $request->input('filter', 'skin');
        $sort   = $request->input('sort', 'time');

        $sort_by = ($sort == "time") ? "upload_at" : $sort;

        if ($filter == "skin") {
            $textures = Texture::like('name', $q)->where(function($query) use ($q) {
                $query->where('public', '=', '1')
                      ->where('type',   '=', 'steve')
                      ->orWhere('type', '=', 'alex');
            })->orderBy($sort_by, 'desc')->get();
        } else {
            $textures = Texture::like('name', $q)
                                ->where('type', $filter)
                                ->where('public', '1')
                                ->orderBy($sort_by, 'desc')->get();
        }

        return view('skinlib.search')->with('user', $this->user)
                                    ->with('sort', $sort)
                                    ->with('filter', $filter)
                                    ->with('q', $q)
                                    ->with('textures', $textures);
    }

    public function show(Request $request)
    {
        $this->validate($request, [
            'tid'    => 'required|integer'
        ]);

        $texture = Texture::find($_GET['tid']);

        if (!$texture || $texture && !Storage::disk('textures')->has($texture->hash)) {
            if (Option::get('auto_del_invalid_texture') == "1") {
                if ($texture)
                    $texture->delete();

                abort(404, '请求的材质文件已经被删除');
            }
            abort(404, '请求的材质文件已经被删除，请联系管理员删除该条目');
        }

        if ($texture->public == "0") {
            if (is_null($this->user) || ($this->user->uid != $texture->uploader && !$this->user->is_admin))
                abort(404, '请求的材质已经设为隐私，仅上传者和管理员可查看');
        }

        return view('skinlib.show')->with('texture', $texture)->with('with_out_filter', true)->with('user', $this->user);
    }

    public function info($tid)
    {
        View::json(Texture::find($tid)->toArray());
    }

    public function upload()
    {
        return view('skinlib.upload')->with('user', $this->user)->with('with_out_filter', true);
    }

    public function handleUpload(Request $request)
    {
        $this->checkUpload($request);

        $t            = new Texture();
        $t->name      = $request->input('name');
        $t->type      = $request->input('type');
        $t->likes     = 1;
        $t->hash      = Utils::upload($_FILES['file']);
        $t->size      = ceil($_FILES['file']['size'] / 1024);
        $t->public    = ($request->input('public') == 'true') ? "1" : "0";
        $t->uploader  = $this->user->uid;
        $t->upload_at = Utils::getTimeFormatted();

        $cost = $t->size * (($t->public == "1") ? Option::get('score_per_storage') : Option::get('private_score_per_storage'));

        if ($this->user->getScore() < $cost)
            View::json('积分不够啦', 7);

        $results = Texture::where('hash', $t->hash)->get();

        if (!$results->isEmpty()) {
            foreach ($results as $result) {
                if ($result->type == $t->type) {
                    View::json([
                        'errno' => 0,
                        'msg' => '已经有人上传过这个材质了，直接添加到衣柜使用吧~',
                        'tid' => $result->tid
                    ]);
                }
            }
        }

        $t->save();

        $this->user->setScore($cost, 'minus');

        if ($this->user->closet->add($t->tid, $t->name)) {
            View::json([
                'errno' => 0,
                'msg'   => '材质 '.$request->input('name').' 上传成功',
                'tid'   => $t->tid
            ]);
        }
    }

    public function delete(Request $request)
    {
        $result = Texture::find($request->tid);

        if (!$result)
            View::json('材质不存在', 1);

        if ($result->uploader != $this->user->uid && !$this->user->is_admin)
            View::json('你不是这个材质的上传者哦', 1);

        // check if file occupied
        if (Texture::where('hash', $result['hash'])->count() == 1)
            Storage::delete($result['hash']);

        $this->user->setScore($result->size * Option::get('score_per_storage'), 'plus');

        if ($result->delete())
            View::json('材质已被成功删除', 0);
    }

    public function privacy($tid, Request $request)
    {
        $t = Texture::find($request->tid);

        if (!$t)
            View::json('材质不存在', 1);

        if ($t->uploader != $this->user->uid && !$this->user->is_admin)
            View::json('你不是这个材质的上传者哦', 1);

        if ($t->setPrivacy(!$t->public)) {
            View::json([
                'errno'  => 0,
                'msg'    => '材质已被设为'.($t->public == "0" ? "隐私" : "公开"),
                'public' => $t->public
            ]);
        }
    }

    public function rename(Request $request) {
        $this->validate($request, [
            'tid'      => 'required|integer',
            'new_name' => 'required|no_special_chars'
        ]);

        $t = Texture::find($request->input('tid'));

        if (!$t)
            View::json('材质不存在', 1);

        if ($t->uploader != $this->user->uid && !$this->user->is_admin)
            View::json('你不是这个材质的上传者哦', 1);

        $t->name = $request->input('new_name');

        if ($t->save()) {
            View::json('材质名称已被成功设置为'.$request->input('new_name'), 0);
        }
    }

    /**
     * Check Uploaded Files
     *
     * @param  Request $request
     * @return void
     */
    private function checkUpload(Request $request)
    {
        $this->validate($request, [
            'name'    => 'required|no_special_chars',
            'file' => 'required|mimes:png|max:10240',
            'public' => 'required'
        ]);

        // if error occured while uploading file
        if ($_FILES['file']["error"] > 0)
            View::json($_FILES['file']["error"], 1);

        $type  = $request->input('type');
        $size  = getimagesize($_FILES['file']["tmp_name"]);
        $ratio = $size[0] / $size[1];

        if ($type == "steve" || $type == "alex") {
            if ($ratio != 2 && $ratio != 1)
                View::json("不是有效的皮肤文件（宽 {$size[0]}，高 {$size[1]}）", 1);
        } elseif ($type == "cape") {
            if ($ratio != 2)
                View::json("不是有效的披风文件（宽 {$size[0]}，高 {$size[1]}）", 1);
        } else {
            View::json('非法参数', 1);
        }
    }

}
