<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LonghornOpen\LaravelCelticLTI\LtiTool;
use Ramsey\Uuid\Uuid;

class LtiController extends Controller
{
    public function ltiMessage(Request $request)
    {
        $tool = new LtiTool();
        $tool->handleRequest();

        $session_data = [];

        // $tool contains information about the launch - which LMS, course, placement, and user this corresponds to.
        // Store these in your database or session, as appropriate for your app.
        if ($tool->getLaunchType() === $tool::LAUNCH_TYPE_LAUNCH) {
            $launch_data = [
                'consumer_guid' => $tool->platform->consumerGuid,
                'context_id' => $tool->context->ltiContextId,
                'resource_link_id' => $tool->resourceLink->ltiResourceLinkId,
                'resource_link_title' => $tool->resourceLink->title,
                'user_id' => $tool->userResult->ltiUserId,
                'result_sourcedid' => $tool->userResult->ltiResultSourcedId,
                'course_name' => $tool->context->title,
                'user_name' => $tool->userResult->fullname,
                'user_email' => $tool->userResult->email,
            ];

            $roles = implode(',', $tool->userResult->roles);
            $isTeacher = strpos($roles, "Instructor") !== FALSE ||
                strpos($roles, "Faculty") !== FALSE ||
                strpos($roles, "TeachingAssistant") !== FALSE ||
                strpos($roles, "ContentDeveloper") !== FALSE ||
                strpos($roles, "Administrator") !== FALSE;

            $session_data['lti_session_exists'] = true;
            $session_data['lti_user_result_dbid'] = $tool->userResult->getRecordId();
            $session_data['lti_resource_link_dbid'] = $tool->resourceLink->getRecordId();
            $session_data['lti_context_dbid'] = $tool->context->getRecordId();
            $session_data['lti_is_teacher'] = $isTeacher;
        }

        $request->session()->put($session_data);
        $uuid = Uuid::uuid4();
        Cache::put('sess'.$uuid, $request->session()->getId(), 300);

        return redirect('/lti_check?id='.$uuid);
    }

    /**
     * Handle being loaded in an iframe, which some browsers won't store a cookie for
     * by opening a new window outside of the iframe where the session actually works.
     */
    public function launchCheck(Request $request)
    {
        if ($request->session()->get('lti_session_exists')) {
            return redirect('/app');
        }

        $id = $request->get('id');
        return <<<TAG
<html><head>
<script>
function deactivate() {
   document.getElementById('link_div').style.display = 'none';
   document.getElementById('message_div').style.display = 'block';
}
setTimeout(deactivate, 4 * 60 * 1000);
</script>
</head><body>
<div id="link_div" style='text-align:center;font-family:sans-serif;font-size:200%;'>
<a href="/lti_redirect?id=$id" target="_blank">Click here</a> to load this tool.
</div>
<div id='message_div' style='text-align:center;font-family:sans-serif;font-size:200%;display:none;'>
Please reload this page in Canvas to launch this tool.
</div>
</body></html>
TAG;
    }

    public function launchRedirect(Request $request)
    {
        $uuid = $request->get('id');
        $session_id = Cache::get('sess'.$uuid);
        $request->session()->setId($session_id);
        $request->session()->start();
        return redirect('/app');
    }
}
