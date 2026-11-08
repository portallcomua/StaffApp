<?php
if (!defined('ABSPATH')) exit;

class StaffApp_REST {
    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        $ns = STAFFAPP_REST_NS;
        register_rest_route($ns, '/login', ['methods'=>'POST','callback'=>[__CLASS__,'login'],'permission_callback'=>'__return_true']);
        register_rest_route($ns, '/employees', ['methods'=>'GET','callback'=>[__CLASS__,'employees'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/employee', ['methods'=>'POST','callback'=>[__CLASS__,'save_employee'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/upload_photo', ['methods'=>'POST','callback'=>[__CLASS__,'upload_photo'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/fire', ['methods'=>'POST','callback'=>[__CLASS__,'fire'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/restore', ['methods'=>'POST','callback'=>[__CLASS__,'restore'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/delete', ['methods'=>'POST','callback'=>[__CLASS__,'delete_employee'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/attendance', ['methods'=>'GET','callback'=>[__CLASS__,'attendance_get'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/attendance', ['methods'=>'POST','callback'=>[__CLASS__,'attendance_save'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/attendance/by_employee', ['methods'=>'GET','callback'=>[__CLASS__,'attendance_by_employee'],'permission_callback'=>[__CLASS__,'auth']]);

        register_rest_route($ns, '/guards', ['methods'=>'GET','callback'=>[__CLASS__,'guards'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/guard', ['methods'=>'POST','callback'=>[__CLASS__,'guard_save'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/guard/update', ['methods'=>'POST','callback'=>[__CLASS__,'guard_update'],'permission_callback'=>[__CLASS__,'auth_manager']]);
        register_rest_route($ns, '/guard/delete', ['methods'=>'POST','callback'=>[__CLASS__,'guard_delete'],'permission_callback'=>[__CLASS__,'auth_manager']]);

        register_rest_route($ns, '/vehicles', ['methods'=>'GET','callback'=>[__CLASS__,'vehicles'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/vehicle', ['methods'=>'POST','callback'=>[__CLASS__,'vehicle_save'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/vehicle/log', ['methods'=>'POST','callback'=>[__CLASS__,'vehicle_log'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/vehicle/logs', ['methods'=>'GET','callback'=>[__CLASS__,'vehicle_logs'],'permission_callback'=>[__CLASS__,'auth']]);

        register_rest_route($ns, '/export/employees', ['methods'=>'GET','callback'=>[__CLASS__,'export_employees'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/export/attendance', ['methods'=>'GET','callback'=>[__CLASS__,'export_attendance'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/export/vehicles', ['methods'=>'GET','callback'=>[__CLASS__,'export_vehicles'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/export/vehicle_logs', ['methods'=>'GET','callback'=>[__CLASS__,'export_vehicle_logs'],'permission_callback'=>[__CLASS__,'auth']]);
        register_rest_route($ns, '/health', ['methods'=>'GET','callback'=>[__CLASS__,'health'],'permission_callback'=>'__return_true']);
    }

    private static function setting($key, $default='') { global $wpdb; $t=$wpdb->prefix.'staffapp_settings'; $v=$wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $t WHERE setting_key=%s",$key)); return $v===null?$default:$v; }
    private static function set_setting($key,$value) { global $wpdb; $t=$wpdb->prefix.'staffapp_settings'; $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE setting_key=%s",$key)); if($id) $wpdb->update($t,['setting_value'=>$value],['id'=>$id]); else $wpdb->insert($t,['setting_key'=>$key,'setting_value'=>$value]); }

    private static function make_token($role,$name,$id=0) {
        $token = wp_generate_password(40,false,false);
        set_transient('staffapp_token_'.$token, ['role'=>$role,'name'=>$name,'id'=>$id], 12 * HOUR_IN_SECONDS);
        return $token;
    }
    public static function token_data($request) {
        $token = $request->get_header('x-staffapp-token');
        if (!$token) $token = $request->get_param('token');
        return $token ? get_transient('staffapp_token_'.$token) : false;
    }
    public static function auth($request) { return StaffApp_License::can_use() && (bool) self::token_data($request); }
    public static function auth_manager($request) { $d=self::token_data($request); return StaffApp_License::can_use() && $d && $d['role']==='manager'; }

    public static function login($request) {
        if (!StaffApp_License::can_use()) {
            return rest_ensure_response(['success'=>false,'error'=>'license_required','message'=>StaffApp_License::status_label()]);
        }
        global $wpdb;
        $data=$request->get_json_params(); $role=sanitize_text_field($data['role'] ?? 'guard'); $pwd=(string)($data['password'] ?? '');
        if ($role === 'manager') {
            $stored = self::setting('manager_password');
            if ($stored && wp_check_password($pwd, $stored)) return rest_ensure_response(['success'=>true,'role'=>'manager','name'=>'Керівник','token'=>self::make_token('manager','Керівник')]);
        } else {
            $guards=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}staffapp_guards");
            foreach($guards as $g) if (wp_check_password($pwd, $g->password)) return rest_ensure_response(['success'=>true,'role'=>'guard','name'=>$g->name,'id'=>(int)$g->id,'token'=>self::make_token('guard',$g->name,(int)$g->id)]);
        }
        return rest_ensure_response(['success'=>false,'error'=>'bad_password']);
    }

    public static function employees() {
        global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}staffapp_employees ORDER BY fired ASC, pib ASC"); $base=wp_upload_dir()['baseurl'].'/staffapp_photos/';
        foreach($rows as $e){
            $e->photos=json_decode($e->photos ?: '[]', true) ?: [];
            $e->descriptors=json_decode($e->descriptors ?: '[]', true) ?: [];
            if($e->main_photo && !filter_var($e->main_photo,FILTER_VALIDATE_URL)) $e->main_photo=$base.$e->main_photo;
        }
        return rest_ensure_response($rows);
    }

    public static function upload_photo($request) {
        $data=$request->get_json_params(); $base64=$data['photo'] ?? ''; if(!preg_match('/^data:image\/(jpeg|jpg|png);base64,/', $base64)) return rest_ensure_response(['success'=>false,'error'=>'bad_image']);
        $parts=explode(',', $base64,2); $image=base64_decode($parts[1]); if(!$image) return rest_ensure_response(['success'=>false,'error'=>'decode_failed']);
        $dir=wp_upload_dir()['basedir'].'/staffapp_photos/'; if(!file_exists($dir)) wp_mkdir_p($dir);
        $filename='staffapp_'.time().'_'.wp_generate_password(8,false,false).'.jpg'; file_put_contents($dir.$filename,$image);
        return rest_ensure_response(['success'=>true,'filename'=>$filename,'url'=>wp_upload_dir()['baseurl'].'/staffapp_photos/'.$filename]);
    }

    public static function save_employee($request) {
        global $wpdb; $d=$request->get_json_params();
        $pib=sanitize_text_field($d['pib'] ?? ''); $pos=sanitize_text_field($d['posada'] ?? ''); if(!$pib||!$pos) return rest_ensure_response(['success'=>false,'error'=>'required']);
        $row=['pib'=>$pib,'posada'=>$pos,'date_in'=>sanitize_text_field($d['date_in'] ?? current_time('Y-m-d')),'date_out'=>null,'main_photo'=>sanitize_text_field($d['main_photo'] ?? ''),'photos'=>wp_json_encode($d['photos'] ?? []),'descriptors'=>wp_json_encode($d['descriptors'] ?? []),'fired'=>0];
        $id=(int)($d['id'] ?? 0);
        if($id){ $ok=$wpdb->update($wpdb->prefix.'staffapp_employees',$row,['id'=>$id]); return rest_ensure_response(['success'=>$ok!==false,'id'=>$id]); }
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}staffapp_employees WHERE pib=%s",$pib));
        if($exists){ $wpdb->update($wpdb->prefix.'staffapp_employees',$row,['id'=>$exists]); return rest_ensure_response(['success'=>true,'id'=>(int)$exists]); }
        $ok=$wpdb->insert($wpdb->prefix.'staffapp_employees',$row); return rest_ensure_response(['success'=>(bool)$ok,'id'=>(int)$wpdb->insert_id,'error'=>$wpdb->last_error]);
    }
    public static function fire($r){global $wpdb;$id=(int)($r->get_json_params()['id']??0);$wpdb->update($wpdb->prefix.'staffapp_employees',['fired'=>1,'date_out'=>current_time('Y-m-d')],['id'=>$id]);return rest_ensure_response(['success'=>true]);}
    public static function restore($r){global $wpdb;$id=(int)($r->get_json_params()['id']??0);$wpdb->update($wpdb->prefix.'staffapp_employees',['fired'=>0,'date_out'=>null],['id'=>$id]);return rest_ensure_response(['success'=>true]);}
    public static function delete_employee($r){global $wpdb;$id=(int)($r->get_json_params()['id']??0);$wpdb->delete($wpdb->prefix.'staffapp_employees',['id'=>$id]);return rest_ensure_response(['success'=>true]);}

    public static function attendance_save($request){global $wpdb;$d=$request->get_json_params();$td=self::token_data($request);$ok=$wpdb->insert($wpdb->prefix.'staffapp_attendance',['emp_id'=>(int)($d['emp_id']??0),'emp_name'=>sanitize_text_field($d['emp_name']??''),'date'=>current_time('Y-m-d'),'time'=>current_time('H:i:s'),'type'=>sanitize_text_field($d['type']??'in'),'guard_name'=>sanitize_text_field($td['name']??($d['guard_name']??''))]);return rest_ensure_response(['success'=>(bool)$ok]);}
    public static function attendance_get($r){global $wpdb;$date=sanitize_text_field($r->get_param('date')?:current_time('Y-m-d'));return rest_ensure_response($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}staffapp_attendance WHERE date=%s ORDER BY time DESC",$date)));}
    public static function attendance_by_employee($r){global $wpdb;$id=(int)$r->get_param('emp_id');return rest_ensure_response($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}staffapp_attendance WHERE emp_id=%d ORDER BY date DESC,time DESC",$id)));}

    public static function guards(){global $wpdb;return rest_ensure_response($wpdb->get_results("SELECT id,name,created_at FROM {$wpdb->prefix}staffapp_guards ORDER BY id DESC"));}
    public static function guard_save($r){global $wpdb;$d=$r->get_json_params();$ok=$wpdb->insert($wpdb->prefix.'staffapp_guards',['name'=>sanitize_text_field($d['name']??''),'password'=>wp_hash_password((string)($d['password']??''))]);return rest_ensure_response(['success'=>(bool)$ok,'id'=>$wpdb->insert_id]);}
    public static function guard_update($r){global $wpdb;$d=$r->get_json_params();$ok=$wpdb->update($wpdb->prefix.'staffapp_guards',['password'=>wp_hash_password((string)($d['password']??''))],['id'=>(int)($d['id']??0)]);return rest_ensure_response(['success'=>$ok!==false]);}
    public static function guard_delete($r){global $wpdb;$d=$r->get_json_params();$ok=$wpdb->delete($wpdb->prefix.'staffapp_guards',['id'=>(int)($d['id']??0)]);return rest_ensure_response(['success'=>(bool)$ok]);}

    private static function normalize_plate($value){
        $raw = mb_strtoupper((string)$value, 'UTF-8');
        $raw = strtr($raw, ['A'=>'А','B'=>'В','C'=>'С','E'=>'Е','H'=>'Н','I'=>'І','K'=>'К','M'=>'М','O'=>'О','P'=>'Р','T'=>'Т','X'=>'Х']);
        $raw = preg_replace('/[^АВЕІКМНОРСТХ0-9]/u', '', $raw);
        $raw = mb_substr($raw, 0, 8, 'UTF-8');
        $a = mb_substr($raw, 0, 2, 'UTF-8');
        $n = mb_substr($raw, 2, 4, 'UTF-8');
        $b = mb_substr($raw, 6, 2, 'UTF-8');
        return trim(implode(' ', array_filter([$a,$n,$b])));
    }
    private static function valid_plate($value, $required=true){
        $value = trim((string)$value);
        if ($value === '') return !$required;
        return (bool)preg_match('/^[АВЕІКМНОРСТХ]{2} [0-9]{4} [АВЕІКМНОРСТХ]{2}$/u', $value);
    }
    private static function normalize_phone($value){
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (strpos($digits, '380') === 0) $digits = substr($digits, 3);
        elseif (strpos($digits, '0') === 0) $digits = substr($digits, 1);
        $digits = substr($digits, 0, 9);
        if ($digits === '') return '';
        if (strlen($digits) !== 9) return '';
        return '+380 '.substr($digits,0,2).' '.substr($digits,2,3).' '.substr($digits,5,2).' '.substr($digits,7,2);
    }
    private static function valid_phone($value){
        $value = trim((string)$value);
        return $value === '' || (bool)preg_match('/^\+380 [0-9]{2} [0-9]{3} [0-9]{2} [0-9]{2}$/', $value);
    }
    private static function attach_last_log($rows){
        global $wpdb;
        foreach($rows as $row){
            $compact = preg_replace('/\s+/u','',(string)$row->number_plate);
            $row->last_log = $wpdb->get_row($wpdb->prepare(
                "SELECT gross,tare,net,event_type,date,time FROM {$wpdb->prefix}staffapp_vehicle_logs WHERE REPLACE(number_plate,' ','')=%s ORDER BY id DESC LIMIT 1",
                $compact
            ));
        }
        return $rows;
    }
    public static function vehicles($r){
        global $wpdb;
        $q=sanitize_text_field($r->get_param('q')?:'');
        $sql="SELECT * FROM {$wpdb->prefix}staffapp_vehicles";
        if($q){
            $compact=preg_replace('/\s+/u','',mb_strtoupper($q,'UTF-8'));
            $like='%'.$wpdb->esc_like($q).'%';
            $like_compact='%'.$wpdb->esc_like($compact).'%';
            $rows=$wpdb->get_results($wpdb->prepare($sql." WHERE REPLACE(number_plate,' ','') LIKE %s OR REPLACE(trailer_plate,' ','') LIKE %s OR brand LIKE %s OR company LIKE %s OR cargo LIKE %s ORDER BY number_plate",$like_compact,$like_compact,$like,$like,$like));
            return rest_ensure_response(self::attach_last_log($rows));
        }
        return rest_ensure_response(self::attach_last_log($wpdb->get_results($sql." ORDER BY created_at DESC")));
    }
    public static function vehicle_save($r){
        global $wpdb;
        $d=$r->get_json_params();
        $plate=self::normalize_plate($d['number_plate']??'');
        if(!self::valid_plate($plate,true))return rest_ensure_response(['success'=>false,'error'=>'Номер авто має формат АА 4444 ББ']);
        $trailer=self::normalize_plate($d['trailer_plate']??'');
        if(!self::valid_plate($trailer,false))return rest_ensure_response(['success'=>false,'error'=>'Номер причепа має формат АА 4444 ББ']);
        $phone_input=trim((string)($d['phone']??''));
        $phone=self::normalize_phone($phone_input);
        if($phone_input!==''&&($phone===''||!self::valid_phone($phone)))return rest_ensure_response(['success'=>false,'error'=>'Телефон має формат +380 00 000 00 00']);
        $row=['number_plate'=>$plate,'trailer_plate'=>$trailer,'brand'=>sanitize_text_field($d['brand']??''),'company'=>sanitize_text_field($d['company']??''),'driver'=>sanitize_text_field($d['driver']??''),'phone'=>$phone,'cargo'=>sanitize_text_field($d['cargo']??''),'photo'=>sanitize_text_field($d['photo']??''),'notes'=>sanitize_textarea_field($d['notes']??'')];
        $compact=preg_replace('/\s+/u','',$plate);
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}staffapp_vehicles WHERE REPLACE(number_plate,' ','')=%s",$compact));
        if($exists){$ok=$wpdb->update($wpdb->prefix.'staffapp_vehicles',$row,['id'=>$exists]);return rest_ensure_response(['success'=>$ok!==false,'id'=>(int)$exists]);}
        $ok=$wpdb->insert($wpdb->prefix.'staffapp_vehicles',$row);
        return rest_ensure_response(['success'=>(bool)$ok,'id'=>(int)$wpdb->insert_id,'error'=>$ok?'':$wpdb->last_error]);
    }
    public static function vehicle_log($r){
        global $wpdb;
        $d=$r->get_json_params();$td=self::token_data($r);
        $plate=self::normalize_plate($d['number_plate']??'');
        if(!self::valid_plate($plate,true))return rest_ensure_response(['success'=>false,'error'=>'Номер авто має формат АА 4444 ББ']);
        $trailer=self::normalize_plate($d['trailer_plate']??'');
        if(!self::valid_plate($trailer,false))return rest_ensure_response(['success'=>false,'error'=>'Номер причепа має формат АА 4444 ББ']);
        $gross=max(0,(float)($d['gross']??0));$tare=max(0,(float)($d['tare']??0));$net=($gross>0&&$tare>0)?max(0,$gross-$tare):0;
        $compact=preg_replace('/\s+/u','',$plate);
        $vehicle_id=(int)($d['vehicle_id']??0);
        if(!$vehicle_id)$vehicle_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}staffapp_vehicles WHERE REPLACE(number_plate,' ','')=%s",$compact));
        $row=['vehicle_id'=>$vehicle_id,'number_plate'=>$plate,'trailer_plate'=>$trailer,'brand'=>sanitize_text_field($d['brand']??''),'company'=>sanitize_text_field($d['company']??''),'driver'=>sanitize_text_field($d['driver']??''),'cargo'=>sanitize_text_field($d['cargo']??''),'gross'=>$gross?:null,'tare'=>$tare?:null,'net'=>$net?:null,'event_type'=>sanitize_text_field($d['event_type']??'in'),'date'=>current_time('Y-m-d'),'time'=>current_time('H:i:s'),'guard_name'=>sanitize_text_field($td['name']??'')];
        $ok=$wpdb->insert($wpdb->prefix.'staffapp_vehicle_logs',$row);
        return rest_ensure_response(['success'=>(bool)$ok,'net'=>$net]);
    }
    public static function vehicle_logs($r){global $wpdb;$date=sanitize_text_field($r->get_param('date')?:current_time('Y-m-d'));return rest_ensure_response($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}staffapp_vehicle_logs WHERE date=%s ORDER BY time DESC",$date)));}

    private static function csv($filename,$header,$rows,$mapper){header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo "\xEF\xBB\xBF";echo implode(',',array_map(fn($h)=>'"'.str_replace('"','""',$h).'"',$header))."\n";foreach($rows as $row){$vals=$mapper($row);echo implode(',',array_map(fn($v)=>'"'.str_replace('"','""',(string)$v).'"',$vals))."\n";}exit;}
    public static function export_employees(){global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}staffapp_employees ORDER BY pib");self::csv('staffapp_employees_'.date('Y-m-d').'.csv',['ID','ПІБ','Посада','Дата прийняття','Дата звільнення','Статус'],$rows,fn($e)=>[$e->id,$e->pib,$e->posada,$e->date_in,$e->date_out,$e->fired?'Звільнений':'Активний']);}
    public static function export_attendance(){global $wpdb;$date=sanitize_text_field($_GET['date']??date('Y-m-d'));$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}staffapp_attendance WHERE date=%s ORDER BY time DESC",$date));self::csv('staffapp_attendance_'.$date.'.csv',['ПІБ','Дата','Час','Подія','Охоронець'],$rows,fn($a)=>[$a->emp_name,$a->date,$a->time,$a->type==='in'?'Прийшов':'Пішов',$a->guard_name]);}
    public static function export_vehicles(){global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}staffapp_vehicles ORDER BY number_plate");self::csv('staffapp_vehicles_'.date('Y-m-d').'.csv',['Номер','Причіп','Марка','Фірма','Водій','Телефон','Вантаж'],$rows,fn($v)=>[$v->number_plate,$v->trailer_plate,$v->brand,$v->company,$v->driver,$v->phone,$v->cargo]);}
    public static function export_vehicle_logs(){global $wpdb;$date=sanitize_text_field($_GET['date']??date('Y-m-d'));$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}staffapp_vehicle_logs WHERE date=%s ORDER BY time DESC",$date));self::csv('staffapp_vehicle_logs_'.$date.'.csv',['Номер','Причіп','Марка','Фірма','Водій','Вантаж','Брутто','Тара','Нетто','Подія','Дата','Час','Охоронець'],$rows,fn($v)=>[$v->number_plate,$v->trailer_plate,$v->brand,$v->company,$v->driver,$v->cargo,$v->gross,$v->tare,$v->net,$v->event_type==='in'?'Заїзд':'Виїзд',$v->date,$v->time,$v->guard_name]);}
    public static function health(){return rest_ensure_response(['success'=>true,'version'=>STAFFAPP_VERSION,'time'=>current_time('mysql')]);}
}
