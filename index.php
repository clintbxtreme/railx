<?php
session_start();

$config = getConfig();
$communityName = $config['community_name'];

function callApi($endpoint) {
    $config = getConfig();
    $token = $config['coc_token'];
    $ch = curl_init("https://api.clashofclans.com/v1/".$endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,
        array(
            "Accept: application/json",
            "authorization: Bearer <{$token}>",
        )
    );
    $result_json = curl_exec($ch);
    $result= json_decode($result_json, true);
    $curl_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($curl_http_code != 200) {
        $error = "API Error!! Endpoint: {$endpoint}, Reason: {$result['reason']}, Message: {$result['message']}, Status Code: {$curl_http_code}";
        error_log($error);
        postToSlack($error);
        return false;
    }
    curl_close($ch);
    return $result;
}

function getConfig() {
    $config = parse_ini_file('./config.ini');
    return $config;
}

function postToSlack($message) {
    $config = getConfig();
    $url = $config['slack_url'];
    $fields = json_encode(array("text" => $message));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
}

$ip = $_SERVER['REMOTE_ADDR'];
if (isset($_SERVER['HTTP_X_REAL_IP'])) {
    $real_ip = preg_replace('/[a-zA-Z]|[:]/', '', $_SERVER['HTTP_X_REAL_IP']);
    if ($real_ip) $ip = $real_ip;
}
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    exit();
}

if (!isset($_SESSION['clan_members']) || empty($_SESSION['clan_members'])) {
    $ua = urlencode($_SERVER['HTTP_USER_AGENT']);
    if(preg_match('(spider|bot)', strtolower($ua)) === 1) {
        echo "Nice to meet you Bot!";
        exit;
    }
    $ua_api_url = "https://useragentapi.com/api/v3/json/7e4a411e/".$ua;
    $user_agent = json_decode(file_get_contents($ua_api_url), true);
    $ua_data = $ua;
    if (isset($user_agent['data']) && $user_agent['data']) {
        $ua_data = $user_agent['data'];
    }
    postToSlack("connection from {$ip}: \n".print_r($ua_data, true));

    $_SESSION['clan_members'] = array();

    //new api
    // $members = callApi("clans/{$config['clan_tag']}/members");
    // $clan_members_details = $members['items'];

    //old way
    $details_json = file_get_contents("https://set7z18fgf.execute-api.us-east-1.amazonaws.com/prod/?route=getClanDetails&clanTag={$config['clan_tag']}");
    $details = json_decode($details_json, true);
    $members = $details['clanDetails']['results'];
    $clan_members_details = $members['memberList'];

    if (is_array($clan_members_details) && count($clan_members_details)) {
        foreach($clan_members_details as $member) {
            $_SESSION['clan_members'][] = $member['name'];
        }
    } else {
        postToSlack("Error: unable to get clan members");
    }
}
if (isset($_POST['inv'])) {
    if ($_POST['token'] != $config['token']) {
        $result = "<h2>Failed! the token you entered is wrong.</h2>";
    }elseif (!in_array($_POST['name'], $_SESSION['clan_members'])) {
        $result = "<h2>Choose a valid clan member! <a href='#' onclick='window.history.back();'>Retry</a></h2>";
    } else {
        $slackHostName = $config['slack_host_name'];
        $slackInviteUrl='https://'.$slackHostName.'.slack.com/api/users.admin.invite?t='.time();
        $slackAuthToken = $config['slack_auth_token'];

        $fields = array(
            'first_name'    => $_POST['name'],
            'email'         => $_POST['email'],
            'token'         => $slackAuthToken,
            'set_active'    => true,
        );
        $fields_string = http_build_query($fields);

        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $slackInviteUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POST, count($fields));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        $replyRaw = curl_exec($ch);
        curl_close($ch);

        $reply=json_decode($replyRaw,true);
        $slack_message = $_POST['name']." - ".$_POST['email'];
        if ($reply['ok']) {
            $result_text = "Success! Check \"{$_POST['email']}\" for an invite from Slack.";
            $slack_message .= " - invited";
        } elseif (in_array($reply['error'], array("already_invited","already_in_team"))) {
            $result_text = "Success! You were already invited.<br> Visit <a href=\"https://{$slackHostName}.slack.com\">{$communityName}</a>";
            $slack_message .= " - ".$reply['error'];
        } elseif ($reply['error'] == 'invalid_email') {
            $result_text = "The email you entered is an invalid email.";
            $slack_message .= " - invalid email";
        } elseif ($reply['error'] == 'invalid_auth') {
            $result_text = "Something has gone wrong. Please contact a system administrator.";
            $slack_message .= "\n".print_r($reply, true);
        } else {
            $result_text = "Error inviting \"{$_POST['email']}\" to Slack.";
            $slack_message .= "\n".print_r($reply, true);
        }
        $result = "<h2>{$result_text}</h2>";
        postToSlack($slack_message);
    }
} else {
    $members_html = "<select name='name' id='slack-name' class='field'><option value='' disabled selected hidden>Please Choose...</option>";
    foreach($_SESSION['clan_members'] as $name) {
        $members_html .= "<option value='{$name}'>{$name}</option>";
    }
    $members_html .= "</select>";

    $result = <<<EOD
        <h2>Enter your email below to join {$communityName} on Slack!</h2>
        </div>
        <div class="content">
            <div class="information">
                <form method="POST" id="join-form" class="form">
                    <input type="text" name="email" placeholder="Enter Your Email Address" id="slack-email" class="field">
                    <input type="text" name="token" placeholder="Enter the invite token you were given" id="slack-token" class="field">
                    {$members_html}
                    <input type="hidden" name="inv" value=1>
                    <input type="submit" value="Join" class="submit">
                </form>
            </div>
        </div>
EOD;
}

?>

<!doctype html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Join the <?= $communityName ?> community on Slack!</title>
        <link href="css/style.css" rel="stylesheet" type="text/css">
        <link href="//fonts.googleapis.com/css?family=Lato:300,400,700,900,700italic|Open+Sans:700italic,400,600,300,700,800" rel="stylesheet" type="text/css">
        <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">
        <script src="//code.jquery.com/jquery-1.11.3.min.js"></script>
        <script src="//code.jquery.com/jquery-migrate-1.2.1.min.js"></script>
        <script src="//cdnjs.cloudflare.com/ajax/libs/underscore.js/1.8.3/underscore-min.js"></script>
        <script>
        </script>
    </head>
    <body>
        <div id="wrapper">
            <div class="main">
                <div class="header"><h1><strong><?= $communityName ?></strong></h1></div>
                <?= $result ?>
            </div>
        </div>
        <script>
            $('#join-form').submit(function(evt) {
                $('.field').each(function() {
                    if (!this.value) {
                        evt.preventDefault();
                        this.classList.add("invalid");
                    }
                })
                $(".invalid").off("change keypress").one("change keypress", function(){
                    this.classList.remove("invalid");
                });
            });
        </script>
    </body>
</html>

