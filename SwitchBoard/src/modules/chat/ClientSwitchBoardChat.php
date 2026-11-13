<?php



class ClientSwitchBoardChat{
private DatabaseManager $databasemanager;
    protected $sbchaturl;
    protected $masurl;
    protected $masclientid;
    protected $massecret;
    public function __construct($sbchaturl)
    {
        $this->sbchaturl = $sbchaturl."/sbchat_api";
        $this->databasemanager = new DatabaseManager();
        $sbchatinfo = $this->databasemanager->getSbchatinfobyId($this->databasemanager->getcurrentuserid());
        if($sbchatinfo){
            $this->masurl = $sbchatinfo ['masurl'];
            $this->masclientid = $sbchatinfo ['masclientid'];
            $this->massecret = $sbchatinfo ['massecret'];
        }else{
            $this->masurl = "";
            $this->masclientid = "";
            $this->massecret = "";

        }


    }
    public function setsbchaturl($sbchaturl){
        $this->sbchaturl = $sbchaturl."/sbchat_api";
    }
     function request_sbchat_api($requester_id_user,$generate_token_for_id_user){
        if ($generate_token_for_id_user == -1){
            // Your requesting a token for yourself
            $generate_token_for_id_user = $requester_id_user;
        }
        $privilege = $this->databasemanager->getcurrentuserprivilegelevel(); // Make sure user is authentificated
        $requester_sbchat_info = $this->databasemanager->getSbchatinfobyId($requester_id_user);
        if($requester_sbchat_info){
            if(empty($requester_sbchat_info['sbchatapitoken'])) {
                echo("sbchatapitoken is empty");
            }else{
                $user_wanting_token = $this->databasemanager->getuserinfobyid($generate_token_for_id_user);
                $user_wanting_token_privilege_level = $this->databasemanager->getuserbyid($generate_token_for_id_user)['privilegelevel'];
                if($user_wanting_token){
                    // Sans compte voip.ms (comité qui n'utilise que le clavardage Web) : les trois champs vides. À moitié
                    // remplis, c'est une erreur de saisie.
                    $sansVoipms = empty($user_wanting_token['voipmsusername']) && empty($user_wanting_token['voipmsapipassword']) && empty($user_wanting_token['didnumber']);
                    if (!$sansVoipms && (empty($user_wanting_token['voipmsusername'])||empty($user_wanting_token['voipmsapipassword'])||empty($user_wanting_token['didnumber']))){
                        echo(_("Veuillez remplir votre nom d'utilisateur voip.ms ainsi que votre mot de passe API et votre DID dans la section informations du compte (ou laissez les trois vides pour un comité sans voip.ms, clavardage Web seulement)."));
                    }else{

                        if($user_wanting_token_privilege_level<=0){
                            $tokenprivilege = 100;
                        }else{
                            $tokenprivilege = 0;
                        }
                        echo(_("Demande d'un nouveau jeton en cours..."));
                        $request = $sansVoipms
                            ? $this->add_new_call_center($requester_sbchat_info['sbchatapitoken'],'','','',$tokenprivilege,false,self::reference_comite($generate_token_for_id_user))
                            : $this->add_new_call_center($requester_sbchat_info['sbchatapitoken'],$user_wanting_token['voipmsusername'],$user_wanting_token['voipmsapipassword'],$user_wanting_token['didnumber'],$tokenprivilege);
                        if(!is_null($request) && array_key_exists('querystatus',$request) && $request['querystatus'] == "Success" && array_key_exists('vec_answer',$request)&& array_key_exists('0',$request['vec_answer'])){
                            echo("It worked");
                            $usersbchatinfo = $this->databasemanager->getSbchatinfobyId($generate_token_for_id_user);
                            $usersbchatinfo['sbchatapitoken']= $request["vec_answer"][0] ;
                            $usersbchatinfo['sbchatserveraddress'] = $requester_sbchat_info['sbchatserveraddress'];
                            $this->databasemanager->setsbchatinfobyuserid($generate_token_for_id_user,$usersbchatinfo);
                        }else {
                            echo("Something went wrong");
                        }
                    }

                }else{
                    echo "Could not find user id $generate_token_for_id_user make sure it's user informations such as voipms account is set up.";
                }

                // get token privilege level

            }
        }
        else{
            echo "Please make sure the user requesting a token as already an admin token set.";
        }
    }
    private function send_api_request($bearerapitoken,$data,$request_url,$userpwd="",$build_query = false,$request_type = "post"){
        // Initialize cURL
        $ch = curl_init($request_url);
        if(strtolower($request_type) == "post")
            curl_setopt($ch, CURLOPT_POST, true);
        if(strtolower($request_type) == "delete")
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Check if we authenticate using password of bearer token
        if(!empty($userpwd)){
            curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
            $http_header = [
            'Content-Type: application/x-www-form-urlencoded'
        ];

        }else{
            $http_header = [
            "Authorization: Bearer $bearerapitoken",
            "Content-Type: application/json",
            "Accept: application/json"
            ];


        }

        // La vérification TLS est activée par défaut (les jetons SBChat/MAS transitent sur
        // cette connexion). Pour un serveur SBChat à certificat auto-signé, voir
        // sbchatCaBundle()/tlsNonVerifieAutorise() dans commun/certificatssbchat.php : fournir
        // le certificat à approuver (recommandé) ou, en dernier recours, désactiver la
        // vérification.
        $caBundle = sbchatCaBundle();
        if ($caBundle !== '') {
            // cURL signale indistinctement (erreur #77) un certificat introuvable, illisible,
            // vide ou malformé : on valide donc nous-mêmes pour dire précisément quoi corriger.
            $problemeCert = problemeCaBundle($caBundle);
            if ($problemeCert !== '') {
                echo "<div class='alert alert-error'>".htmlspecialchars($problemeCert)."</div>";
                error_log("SBChat: $problemeCert");
            } else {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
        } elseif (tlsNonVerifieAutorise()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }



        // Add headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, $http_header);
        if($data != []){
            if($build_query){
                $dataencoded = http_build_query($data);

            }else{
                $dataencoded = json_encode($data);
            }
            // Attach JSON body
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataencoded);

        }else{
             $dataencoded = "No data encoded";
        }


        // Execute
        $response = curl_exec($ch);
        // Check for errors
        if ($response === false) {
            // Get the error message
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            echo "cURL Error #{$errno}: {$error}\n";

            // Le détail complet de curl_getinfo() (URL interne, chemins serveur comme
            // cainfo, IPs) n'est montré qu'en mode debug : utile pour diagnostiquer, mais
            // c'est de la divulgation d'information interne pour un utilisateur normal.
            if (modeDebugActif()) {
                $info = curl_getinfo($ch);
                print_r($info);
            }
        } else {
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['dataencoded' => $dataencoded, 'response' => $response,'httpCode' => $httpCode];
    }
    private function get_mas_token(){

        if(!$this->masclientid || !$this->massecret || !$this->masurl){
            echo _("Veuillez configurer vos informations de serveur MAS dans le formulaire d'informations SwitchBoard Chat.");
            exit(1);
        }
        $data = [
            'grant_type' => 'client_credentials',
            'scope' => 'urn:mas:admin'
        ];
        $request = self::send_api_request("",$data,$this->masurl."/oauth2/token","$this->masclientid:$this->massecret",build_query:true);

        // Show result

        $request_array = json_decode($request['response'],true);
        if($bearer_token = $request_array['access_token']){
            return $bearer_token;
        }else{
            echo "Error while fetching bearer token";
            echo "HTTP Status:".$request['httpCode']."<br>\n";
            echo "Response: ".$request['response']."<br>\n";
            return false;
        }

    }
    // POST /api/admin/v1/users
    public function add_mas_user($username){
        if(self::get_mas_user_id($username)){
            echo "<h2>"._("Utilisateur déjà existant.")."</h2>";
            return false;
        }
        $bearer_token = self::get_mas_token();
        $data = ["username" => $username,
                "skip_homeserver_check"  =>  false];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users",request_type:"post");
        if($request['httpCode'] == 201){
            echo "<h2>"._("Utilisateur créé.")."</h2>";
            $request_array = json_decode($request['response'],true);
            return $request_array['data']['id'];
        }else{
            echo "<h2>"._("Erreur lors de la création de l'utilisateur :")."</h2>";
            echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
            return false;
        }
        /*array(3) { ["dataencoded"]=> string(51) "{"username":"string","skip_homeserver_check":false}" ["response"]=> string(324) "{"data":{"type":"user","id":"01KFEH8VPGMQJ9E2B20516GJY1","attributes":{"username":"string","created_at":"2026-01-20T20:24:22.480114051Z","locked_at":null,"deactivated_at":null,"admin":false},"links":{"self":"/api/admin/v1/users/01KFEH8VPGMQJ9E2B20516GJY1"}},"links":{"self":"/api/admin/v1/users/01KFEH8VPGMQJ9E2B20516GJY1"}}" ["httpCode"]=> int(201) } */
    }
    //GET /api/admin/v1/users/{id}
    public function get_mas_username_by_id($user_id){
        $bearer_token = self::get_mas_token();
        $data = [];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users/$user_id",request_type:"get");
        if($request['httpCode'] == 200){
            $response = json_decode($request['response'],true);
            return $response['data']['attributes']['username'];
        }else{
            echo _("Utilisateur non trouvé....");
            return false;
        }


    }
    /// POST /api/admin/v1/users/{id}/set-password
    public function set_mas_user_password($username,$password,$userid=""){
        if(empty($userid)){
            $request = self::get_mas_user($username);
            if(!$response = json_decode($request['response'],true)){
                echo "<h2>"._("Impossible d'accéder au serveur !"."</h2>");
                echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
                return false;
            }
            $userid = $response['data']['id'];
        }
        $bearer_token = self::get_mas_token();
        $data = ["password" => $password,
                "skip_password_check"  =>  true];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users/$userid/set-password",request_type:"post");
        if($request['httpCode'] == 204){
            echo "<h2>"._("Mot de passe modifié avec succès.")."</h2>";
            return true;
        }else{
            echo "<h2>"._("Erreur lors de la modification du mot de passe :")."</h2>";
            $request_array = json_decode($request['response'],true);
            if (array_key_exists("errors",$request_array)){
                echo "<h2>"._("Erreur, retour de l'api : ").$request_array['errors'][0]['title']."</h2>";
            }else {
                 echo "<h2>"._("Erreur")."<br>";
                 echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
                 echo "</h2>";
            }
            return false;
        }
    }
    /// Nom du serveur Matrix (la partie après « : » dans @lita:matrix.example.org), pour le lien
    /// d'invitation Element X. Déduit de l'adresse MAS : /.well-known/matrix/client de son domaine donne
    /// l'adresse du serveur Matrix, et /_matrix/key/v2/server (public) donne son vrai nom.
    /// À défaut, le domaine de l'adresse MAS. Gardé en session pour ne pas refaire les appels.
    public function get_matrix_server_name(){
        $host = parse_url((string)$this->masurl, PHP_URL_HOST);
        if(empty($host)){
            return "";
        }
        $cachekey = 'sbchat_matrix_server_name_'.$host;
        if(!empty($_SESSION[$cachekey])){
            return $_SESSION[$cachekey];
        }
        $base_url = "https://".$host;
        $wellknown = self::fetch_public_json("https://".$host."/.well-known/matrix/client");
        if(!empty($wellknown['m.homeserver']['base_url'])){
            $base_url = rtrim($wellknown['m.homeserver']['base_url'], '/');
        }
        $keys = self::fetch_public_json($base_url."/_matrix/key/v2/server");
        $server_name = $host;
        if(!empty($keys['server_name']) && preg_match('/^[a-z0-9.-]+(:[0-9]+)?$/i', $keys['server_name'])){
            $server_name = strtolower($keys['server_name']);
        }
        $_SESSION[$cachekey] = $server_name;
        return $server_name;
    }
    /// GET sans authentification d'un document JSON public ; null en cas d'échec. Même règles TLS que
    /// send_api_request.
    private function fetch_public_json($url){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json"]);
        $caBundle = sbchatCaBundle();
        if ($caBundle !== '' && problemeCaBundle($caBundle) === '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        } elseif ($caBundle === '' && tlsNonVerifieAutorise()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($response === false || $httpCode != 200){
            return null;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
    /// POST /api/admin/v1/users/{id}/set-admin
    /// Donne (ou retire) les droits d'administrateur du serveur Matrix à un utilisateur MAS.
    /// L'appelant doit avoir vérifié que l'utilisateur courant est lui-même administrateur.
    public function set_mas_user_admin($user_id,$admin){
        $bearer_token = self::get_mas_token();
        $data = ["admin" => (bool)$admin];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users/$user_id/set-admin",request_type:"post");
        if($request['httpCode'] >= 200 && $request['httpCode'] < 300){
            echo "<h2>".($admin ? _("Droits d'administrateur Matrix accordés.") : _("Droits d'administrateur Matrix retirés."))."</h2>";
            return true;
        }else{
            echo "<h2 style='color:red;'>"._("Erreur lors de la modification des droits d'administrateur Matrix :")."</h2>";
            echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
            return false;
        }
    }
    /// GET /api/admin/v1/user-emails/{id}
    public function get_mas_user_email($user_id){
        //Carl
        // Find email id by using username
        $email_id = self::get_email_id_by_user_id($user_id);
        if(!$email_id){
            echo _("Pas d'identifiant utilisateur pour cet identifiant de courriel.");
            return false;
        }
        $bearer_token = self::get_mas_token();
        $data = [];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/user-emails/$email_id",request_type:"get");
        switch ($request['httpCode']){
            case 200:
                $response = json_decode($request['response'],true);
                $user_email = [];
                $user_email['email'] = $response['data']['attributes']['email'];
                $user_email['email_id'] = $email_id;
                return $user_email;
            case 404:
                return false;

        }
        echo "<br>Error getting user email<br>";
        echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
        return false;
    }
     /// GET /api/admin/v1/user-emails/{id}
    public function delete_mas_user_email_except($user_id,$email_id){
        $bearer_token = self::get_mas_token();
        $data = [];
        $url = $this->masurl."/api/admin/v1/user-emails?filter[user]=$user_id";
        $request = self::send_api_request($bearer_token,$data,$url,request_type:"get");

        if($request['httpCode']==200){
            $response = json_decode($request['response'],true);
                if ($response['meta']['count'] !=0){
                    foreach ($response['data'] as $email_entry){
                        $email_entry_id = $email_entry['id'];
                        if($email_entry_id != $email_id){
                            echo "<br>Deleting email_id: $email_entry_id<br>";
                            $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/user-emails/$email_entry_id",request_type:"delete");


                        }

                    }
                }
        }else{
            echo _("Utilisateur non trouvé pour cette adresse utilisateur : $user_id.");
        }
    }

    ///POST /api/admin/v1/user-emails
    public function set_mas_user_email($user_id,$email){
        $bearer_token = self::get_mas_token();
        $data = ["user_id" => $user_id,
                    "email" => $email];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/user-emails",request_type:"post");
        switch ($request['httpCode']){
                case 201:
                    echo _("Courriel créé avec succès");
                    $response = json_decode($request['response'],true);
                    return $response['data']['id'];
                case 400:
                    echo _("Courriel non valide");
                    return false;
                case 409:
                    echo _("Courriel déjà en utilisation");
                    echo "<p>"._("L'adresse courriel est déjà enregistrée à cette utilisateur : ").self::get_mas_username_by_id($user_id)."</p>";
                    return false;

        }

    }

    /// GET /api/admin/v1/user-emails
    public function get_mas_user_by_email($email){
        $bearer_token = self::get_mas_token();
        $data = [];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/user-emails?filter[email]=$email",request_type:"get");
        if($request['httpCode']==200){
            $response = json_decode($request['response'],true);
            if($response['meta']['count'] == 0)
                return false;
            return $response['data'][0]['attributes']['user_id'];
        }else{
            echo _("Utilisateur non trouvé pour cette adresse courriel : $email.");
        }
    }
    /// GET /api/admin/v1/user-emails
    public function list_mas_email_address(){
        $bearer_token = self::get_mas_token();
        $data = [];
        $url = $this->masurl."/api/admin/v1/user-emails?page[first]=10000";
        $request = self::send_api_request($bearer_token,$data,$url,request_type:"get");
        $request_array = json_decode($request['response'],true);
        echo "<pre>" . json_encode($request_array['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    }

    /// GET /api/admin/v1/user-emails
    public function get_email_id_by_user_id($user_id){
        $bearer_token = self::get_mas_token();
        $data = [];
        $url = $this->masurl."/api/admin/v1/user-emails?filter[user]=$user_id";
        $request = self::send_api_request($bearer_token,$data,$url,request_type:"get");

        if($request['httpCode']==200){
            $response = json_decode($request['response'],true);
                if ($response['meta']['count'] !=0){
                    $email_id = $response['data'][0]['id'];
                    return $email_id;
                }
                else return false;
        }else{
            echo _("Utilisateur non trouvé pour cette adresse utilisateur : $user_id.");
        }
    }

    // GET /api/admin/v1/users/by-username/{username}
    // localpart only ex. jeanp instead for @jeanp:matrix.org
    public function get_mas_user($username){
        $bearer_token = self::get_mas_token();
        $data = [];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users/by-username/".rawurlencode($username),request_type:"get");
        return $request;
    }
    // Recherche sûre d'un utilisateur MAS : distingue « trouvé », « inexistant » et « erreur ».
    // get_mas_user_id() rend false dans les deux derniers cas : une panne du serveur passait
    // alors pour « l'utilisateur n'existe pas ».
    // Retourne ['status' => 'found'|'notfound'|'error', 'id' => ?string, 'detail' => string].
    public function lookup_mas_user($username){
        $request = self::get_mas_user($username);
        $httpCode = intval($request['httpCode'] ?? 0);
        $response = json_decode($request['response'] ?? '',true);
        if($httpCode == 200 && is_array($response) && !empty($response['data']['id'])){
            return ['status' => 'found', 'id' => $response['data']['id'], 'detail' => ''];
        }
        if($httpCode == 404){
            return ['status' => 'notfound', 'id' => null, 'detail' => ''];
        }
        return ['status' => 'error', 'id' => null, 'detail' => "HTTP $httpCode : ".($request['response'] ?? '')];
    }
    public function get_mas_user_id($username){
        $request = self::get_mas_user($username);
        if(!$response = json_decode($request['response'],true)){
            echo "<h2>"._("Impossible d'accéder au serveur !"."</h2>");
            echo "HTTP ".htmlspecialchars((string)($request['httpCode'] ?? ''))." : ".htmlspecialchars((string)($request['response'] ?? ''));
            return false;
        }
        if(array_key_exists('errors',$response)){
            echo $response['errors'][0]['title'];
            return false;
        }
        ;
        if(array_key_exists('data',$response)){
            return $response['data']['id'];
        }
    }

    public function list_mas_users(){
        $bearer_token = self::get_mas_token();
        $data = [];
        $request = self::send_api_request($bearer_token,$data,$this->masurl."/api/admin/v1/users?filter[can_request_admin]=true&filter[status]=active&page[first]=100",request_type:"get");
        $request_array = json_decode($request['response'],true);
        echo "<pre>" . json_encode($request_array['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
    }

    /// Référence stable d'un comité pour SwitchBoard Chat (centre d'appel sans voip.ms) : l'identifiant de cette
    /// installation de SwitchBoard (créé une fois dans config/) et celui du compte.
    public static function reference_comite($iduser){
        $fichier = CONFIG_DIRECTORY.'instance-id';
        $id = is_readable($fichier) ? trim((string)file_get_contents($fichier)) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            @file_put_contents($fichier, $id);
            @chmod($fichier, 0600);
        }
        return 'switchboard:'.$id.':'.intval($iduser);
    }
    public function add_new_call_center($callcenterapitoken,$voip_ms_username,$voip_ms_api_password,$call_center_did,$privilege_level=0,$renew_api_token=false,$committee_ref=''){
        $data = [
            "command" => "add_new_call_center",
            "voip_ms_username" =>  $voip_ms_username,
            "voip_ms_api_password" =>  $voip_ms_api_password,
            "call_center_did" => (string)$call_center_did,
            "privilege_level" =>  $privilege_level,
            "renew_api_token" => $renew_api_token,
        ];
        if ($committee_ref !== '') {
            $data["committee_ref"] = $committee_ref;
        }
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        return json_decode($request['response'],true);
    }

    // Sites Web reliés au comité (clavardage Web) : code de jumelage à coller dans WP Chat, liste, retrait.
    // Le code est valide 30 minutes et ne sert qu'une fois. Retourne le tableau décodé du serveur, ou null.
    public function create_web_pairing($callcenterapitoken){
        $request = self::send_api_request($callcenterapitoken, ["command" => "create_web_pairing", "api_token" => $callcenterapitoken], $this->sbchaturl);
        $r = json_decode((string)($request['response'] ?? ''), true);
        if (!is_array($r) || ($r['querystatus'] ?? '') !== 'Success') {
            return null;
        }
        return json_decode((string)$r['message'], true);
    }
    public function list_web_sites($callcenterapitoken){
        $request = self::send_api_request($callcenterapitoken, ["command" => "list_web_sites", "api_token" => $callcenterapitoken], $this->sbchaturl);
        $r = json_decode((string)($request['response'] ?? ''), true);
        if (!is_array($r) || ($r['querystatus'] ?? '') !== 'Success') {
            return null;
        }
        $liste = json_decode((string)$r['message'], true);
        return is_array($liste) ? $liste : null;
    }
    public function remove_web_site($callcenterapitoken, $origin){
        $request = self::send_api_request($callcenterapitoken, ["command" => "remove_web_site", "api_token" => $callcenterapitoken, "origin" => $origin], $this->sbchaturl);
        $r = json_decode((string)($request['response'] ?? ''), true);
        return is_array($r) && ($r['querystatus'] ?? '') === 'Success';
    }
    /// La chaîne à coller dans WP Chat : l'adresse publique du clavardage Web (celle que le serveur donne, sinon
    /// l'adresse du serveur connue ici) et le code
    public function chaine_jumelage($jumelage){
        $base = !empty($jumelage['public_url']) ? $jumelage['public_url'] : substr($this->sbchaturl, 0, -strlen('/sbchat_api'));
        return rtrim((string)$base, '/').'#'.$jumelage['code'];
    }

    public function update_call_center($callcenterapitoken,$voip_ms_username,$voip_ms_api_password,$call_center_did){
        $data = [
            "command" => "update_call_center",
            "api_token" => $callcenterapitoken,
            "voip_ms_username" =>  $voip_ms_username,
            "voip_ms_api_password" =>  $voip_ms_api_password,
            "call_center_did" => $call_center_did,
        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        return json_decode($request['response'],true);
    }

    public function delete_call_center($callcenterapitoken,$callcenter_to_delete_api_token){
        $data = [
            "command" => "delete_call_center",
            "api_token" =>  $callcenter_to_delete_api_token
        ];
        self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
    }
    public function change_call_center_respondents($callcenterapitoken,$respondent){
        $data = [
            "command" => "change_respondents",
            "api_token" => $callcenterapitoken,
            "respondent" =>  $respondent
        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        return json_decode($request['response'],true);
    }
    public function send_message_to_respondents($callcenterapitoken,$respondent,$message){
        $data = [
            "command" => "send_message",
            "respondent" =>  $respondent,
            "message" =>  $message
        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        return json_decode($request['response'],true);
    }
    public function list_macros($callcenterapitoken){
        $data = [
            "command" => "list_macros",
            "api_token" => $callcenterapitoken,

        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);

        // Liste vide ([]) : un centre d'appel sans macro, ce n'est pas une panne. Échec (null) : pas de réponse,
        // ou réponse qui n'est pas une liste (jeton refusé, serveur SBChat injoignable).
        $response_decoded = json_decode((string)($request['response'] ?? ''),true);
        $message_decoded = is_array($response_decoded) ? json_decode((string)($response_decoded['message'] ?? ''),true) : null;
        return is_array($message_decoded) ? $message_decoded : null;
    }
    // Entente de confidentialité des bénévoles (signée dans Element, voir switchboard-chat src/agreement.rs).
    // Retourne le tableau décodé (configured, version, sha256, text_fr, text_en, respondents[]) ou null.
    public function list_agreement_signatures($callcenterapitoken){
        $data = [
            "command" => "list_agreement_signatures",
            "api_token" => $callcenterapitoken,
        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        $response_decoded = json_decode($request['response'] ?? '',true);
        if(empty($response_decoded) || ($response_decoded['querystatus'] ?? '') !== 'Success'){
            return null;
        }
        return json_decode($response_decoded['message'],true);
    }
    // Envoie l'entente maintenant aux bénévoles qui ne l'ont pas signée. Retourne [ok, nombre ou message d'erreur].
    public function send_agreement($callcenterapitoken){
        $data = [
            "command" => "send_agreement",
            "api_token" => $callcenterapitoken,
        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        $response_decoded = json_decode($request['response'] ?? '',true);
        if(empty($response_decoded)){
            return [false, null];
        }
        if(($response_decoded['querystatus'] ?? '') !== 'Success'){
            return [false, $response_decoded['message'] ?? null];
        }
        $message = json_decode($response_decoded['message'],true);
        return [true, $message['sent'] ?? 0];
    }
    public function update_macro($callcenterapitoken,$macro_info){
        $data = [
            "command" => "update_macro",
            "api_token" => $callcenterapitoken,
            "macro_info" => $macro_info,

        ];
        $request = self::send_api_request($callcenterapitoken,$data,$this->sbchaturl);
        $response_decoded = json_decode($request['response'],true);
        if(empty($response_decoded)){
            return null;
        }
        return $response_decoded['message'] ?? null;
    }



}
