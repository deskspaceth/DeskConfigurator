if (!defined('ABSPATH')) {
    exit;
}

if (!defined('EC_DRAW_API_URL')) {
    define('EC_DRAW_API_URL', 'https://script.google.com/macros/s/AKfycbw0PFtjBfhHmOK6IkA-ITzK6WhrtkPjM_kbKnjkkS-rFPym9I3joyJV5vLbSJr4G1VB3g/exec');
}

add_action('wp_ajax_dpb_send_proposal_v5',        'dpb_handle_send_proposal_v5');
add_action('wp_ajax_nopriv_dpb_send_proposal_v5', 'dpb_handle_send_proposal_v5');

function dpb_handle_send_proposal_v5() {
    check_ajax_referer('dpb_security_nonce_v4', 'nonce');

    // --- 1. รับค่า ---
    $intent  = sanitize_text_field($_POST['intent']   ?? 'quote');   // 'quote' | 'inquiry'
    $info    = $_POST['contact_info'] ?? [];
    $name    = sanitize_text_field($info['name']    ?? '-');
    $email   = sanitize_email($info['email']        ?? '-');
    $tel     = sanitize_text_field($info['tel']     ?? '-');
    $line_id = sanitize_text_field($info['line_id'] ?? '-');
    $question = sanitize_textarea_field($_POST['question'] ?? '');

    // --- 2. รูปภาพ (logic เดิม) ---
    $image_data  = $_POST['image_data'] ?? '';
    $attachments = [];
    $temp_files  = [];

    if (!empty($image_data)) {
        $parts = explode(";base64,", $image_data);
        if (count($parts) === 2) {
            $decoded = base64_decode(str_replace(' ', '+', $parts[1]));
            if ($decoded !== false) {
                $finfo     = new finfo(FILEINFO_MIME_TYPE);
                $real_mime = $finfo->buffer($decoded);
                $allowed   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (array_key_exists($real_mime, $allowed)) {
                    $upload  = wp_upload_dir();
                    $fname   = 'proposal_' . time() . '_' . rand(100,999) . '.' . $allowed[$real_mime];
                    $fpath   = $upload['path'] . '/' . $fname;
                    file_put_contents($fpath, $decoded);
                    $attachments[] = $fpath;
                    $temp_files[]  = $fpath;
                }
            }
        }
    }

    // --- 3. Summary ---
    $summary_data = json_decode(stripslashes($_POST['summary_data'] ?? '{}'), true);

    // Helper table builder
    if (!function_exists('dpb_build_table_v5')) {
        function dpb_build_table_v5($data, $title) {
            if (empty($data)) return '';
            $html  = "<div style='margin-bottom:20px;'>";
            $html .= "<h3 style='color:#b69652;border-bottom:2px solid #b69652;padding-bottom:5px;margin:0 0 10px;font-size:16px;font-weight: 500;letter-spacing: 0.8px;'>$title</h3>";
            $html .= "<table style='width:100%;border-collapse:collapse;font-size:14px;color:#333;'>";
            foreach ($data as $k => $v) {
                if (is_array($v)) continue;
                $html .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;width:40%;color:#666;'>".esc_html($k)."</td>";
                $html .= "<td style='padding:8px;border-bottom:1px solid #eee;font-weight:600;'>".esc_html($v)."</td></tr>";
            }
            $html .= "</table></div>";
            return $html;
        }
    }

    $tbl_spec = dpb_build_table_v5($summary_data['สเปคโต๊ะ'] ?? [], 'ข้อมูลสเปคโต๊ะ');

    $opts_html = '';
    if (!empty($summary_data['รายการ_Options'])) {
        $opts_html .= "<div style='margin-bottom:20px;'><h3 style='color:#b69652;border-bottom:2px solid #b69652;padding-bottom:5px;margin:0 0 10px;font-size:16px; font-weight: 500;    letter-spacing: 0.8px;'>รายการ Options เสริม</h3>";
        $opts_html .= "<table style='width:100%;border-collapse:collapse;font-size:14px;color:#333;'>";
        $opts_html .= "<tr style='background:#f5f5f5;'><th style='padding:8px;text-align:left;'>รายการ</th><th style='padding:8px;text-align:center;'>จำนวน</th></tr>";
        foreach ($summary_data['รายการ_Options'] as $opt) {
            $opts_html .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;'>".esc_html($opt['รายการ']??'')."</td>";
            $opts_html .= "<td style='padding:8px;border-bottom:1px solid #eee;text-align:center;font-weight:bold;'>".esc_html($opt['จำนวน']??'')."</td></tr>";
        }
        $opts_html .= "</table></div>";
    }

    // ================================================================
    // DETECT: ประเภทชื่อ (นิติบุคคล vs บุคคลธรรมดา)
    // ================================================================
    $name_lower = mb_strtolower($name, 'UTF-8');

    // --- นิติบุคคล: คำเต็มและคำย่อทุกรูปแบบ ---
    $corp_keywords = [
        // บริษัทจำกัด / บริษัทมหาชน
        'บริษัท', 'บจก.', 'บจก', 'บจ.', 'บจ', 'บมจ.', 'บมจ',
        // ห้างหุ้นส่วน
        'ห้างหุ้นส่วนจำกัด', 'ห้างหุ้นส่วนสามัญ', 'หจก.', 'หจก', 'หสน.', 'หสน',
        // องค์กรอื่นๆ
        'มูลนิธิ', 'สมาคม', 'สหกรณ์',
        'มหาวิทยาลัย', 'วิทยาลัย', 'โรงเรียน',
        'โรงพยาบาล', 'รพ.',
        'องค์การ', 'องค์กร',
        'กระทรวง', 'กรม', 'สำนักงาน',
    ];

    // --- คำนำหน้าชื่อบุคคล ---
    $person_prefixes = [
        'นาย', 'นาง', 'นางสาว', 'น.ส.',
        'ดร.', 'ศ.', 'รศ.', 'ผศ.',
        'นพ.', 'ทพ.', 'ภญ.', 'ภก.',
        'คุณ',
    ];

    // --- ตรวจว่าเป็นนิติบุคคลไหม ---
    $is_corporate = false;
    foreach ($corp_keywords as $kw) {
        if (mb_strpos($name_lower, mb_strtolower($kw, 'UTF-8')) !== false) {
            $is_corporate = true;
            break;
        }
    }

    // --- ตรวจว่ามีคำนำหน้าบุคคลอยู่แล้วไหม ---
    $has_person_prefix = false;
    foreach ($person_prefixes as $prefix) {
        if (mb_strpos($name_lower, mb_strtolower($prefix, 'UTF-8')) !== false) {
            $has_person_prefix = true;
            break;
        }
    }

    // --- กำหนด display_name และ contact_label ---
    if ($is_corporate) {
        $display_name  = $name;                  // ใช้ชื่อตามที่กรอก ไม่เติมอะไร
        $contact_label = 'บริษัท / องค์กร';
    } elseif ($has_person_prefix) {
        $display_name  = $name;                  // มีคำนำหน้าอยู่แล้ว ไม่เติมซ้ำ
        $contact_label = 'ชื่อ';
    } else {
        $display_name  = 'คุณ ' . $name;         // บุคคลทั่วไป ไม่มีคำนำหน้า
        $contact_label = 'ชื่อ';
    }

    // ================================================================
    // CONTACT BLOCK — ใช้ $display_name และ $contact_label ที่ถูกต้อง
    // ================================================================
    $contact_block = "
    <div style='background:#f9f9f9;padding:15px;border-left:4px solid #b69652;margin-bottom:25px;border-radius:0 6px 6px 0;'>
        <p style='margin:5px 0;font-size:14px;'><strong style='color:#666;'>{$contact_label}:</strong> {$name}</p>
        <p style='margin:5px 0;font-size:14px;'><strong style='color:#666;'>อีเมล:</strong> $email</p>
        <p style='margin:5px 0;font-size:14px;'><strong style='color:#666;'>เบอร์โทร:</strong> $tel</p>
        <p style='margin:5px 0;font-size:14px;'><strong style='color:#666;'>Line ID:</strong> $line_id</p>
    </div>";

    // ================================================================
    // EMAIL HEADER TEMPLATE (shared wrapper)
    // ================================================================
    $email_wrap_open = "
    <div style='font-family:prompt;max-width:720px;margin:0 auto;border:1px solid #e0e0e0;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.06);'>
        <div style='background:#fff;padding:24px;text-align:center;border-bottom:3px solid #b69652;'>
            <h1 style='font-family:prompt,serif;color:#b69652;margin:0;font-size:36px;letter-spacing:0.1em;text-transform:uppercase;'>DESKSPACE</h1>
        </div>
        <div style='padding:30px;'>";
    $email_wrap_close = "
            <hr style='border:0;border-top:1px solid #eee;margin:30px 0;'>
            <p style='text-align:center;color:#aaa;font-size:11px;letter-spacing:0.05em;'>ส่งจากระบบ DeskSpace Configurator</p>
        </div>
    </div>";

    $to_admin       = 'team@boxbillion.com';
    $from_system    = 'Website Deskspace Configurator <deskspaceth@gmail.com>';
    $headers_base   = ['Content-Type: text/html; charset=UTF-8', "From: $from_system", "Reply-To: $name <$email>"];

    // ================================================================
    // BRANCH: QUOTE
    // ================================================================
    if ($intent === 'quote') {
        $safe_question = esc_html($question);

        $subject_admin = "ขอใบเสนอราคา (Customized Desk): {$display_name}";

        $extra_note_html = '';
        if (!empty($safe_question)) {
            $extra_note_html = "
            <div style='margin-bottom:25px;'>
                <h3 style='color: #b3261e;letter-spacing: 0.8px;font-weight: 500;border-bottom: 2px solid #b3261e;padding-bottom: 5px;margin: 0 0 10px;font-size: 16px;'>ข้อความเพิ่มเติมจากลูกค้า</h3>
                <div style='background: #c34a4a;letter-spacing: 0.8px;border: 0px solid #ff5d5d;border-radius: 8px;padding: 16px;font-size: 14px;line-height: 1.7;color: #ffffff;'>
                    $safe_question
                </div>
            </div>";
        }

        $message_admin = $email_wrap_open . "
            <h2 style='color:#1a1714;font-size:24px;margin:0 0 20px;'>
                ลูกค้าขอใบเสนอราคา (Website DeskSpace Configurator)
            </h2>
            $contact_block
            $extra_note_html
            $tbl_spec
            $opts_html"
        . $email_wrap_close;

        $sent_admin = wp_mail($to_admin, $subject_admin, $message_admin, $headers_base, $attachments);

        if ($email && $email !== '-') {
            $message_customer = $email_wrap_open . "
                <div style='text-align:center;padding:10px 0 30px;'>
                    <h2 style='color:#333;font-size:20px;margin-bottom:12px;'>Thank You For Choosing Us</h2>
                    <p style='color:#666;font-size:15px;line-height:1.7;'>
                        ทาง Deskspace ได้รับคำขอใบเสนอราคาจาก <strong>{$display_name}</strong> เรียบร้อยแล้ว<br>
                        ทีมงานจะประเมินราคาและติดต่อกลับทาง <strong>Line หรืออีเมล</strong> โดยเร็วที่สุดค่ะ
                    </p>
                    <p style='color:#999;font-size:13px;margin-top:16px;line-height:1.6;'>
                        We've received your quotation request and will get back<br>to you via Line or Email shortly.
                    </p>
                </div>
                <div style='background:#f9f7f3;border-radius:8px;padding:15px;text-align:center;font-size:12px;color:#999;'>
                    หากมีข้อสงสัย: team@boxbillion.com | Line: @deskspace
                </div>"
            . $email_wrap_close;

            wp_mail($email, 'Deskspace — เราได้รับคำขอใบเสนอราคาของคุณแล้ว',
                $message_customer,
                ['Content-Type: text/html; charset=UTF-8', 'From: Deskspace <deskspaceth@gmail.com>']
            );
        }

    // ================================================================
    // BRANCH: INQUIRY
    // ================================================================
    } else {

        $safe_question = esc_html($question);

        $subject_admin = "สอบถามข้อมูลเพิ่มเติม: {$display_name}";

        $message_admin = $email_wrap_open . "
            <h2 style='color:#1a1714;font-size: 24px;font-weight: 500;margin:0 0 20px'>
                ลูกค้าสอบถามข้อมูลแบบโต๊ะ Customized Desk
            </h2>
            <div style='margin-bottom:20px;'>
                <h3 style='color:#b69652;border-bottom:2px solid #b69652;padding-bottom:5px;margin:0 0 10px;font-size:16px;font-weight: 500; letter-spacing: 0.8px;'>ข้อมูลติดต่อกลับ</h3>
                $contact_block
            </div>
            <div style='margin-bottom:25px;'>
                <h3 style='color:#b69652;border-bottom:2px solid #b69652;padding-bottom:5px;margin:0 0 10px;font-size:16px;font-weight: 500; letter-spacing: 0.8px;'>ข้อความจากลูกค้า</h3>
                <div style='background:#fffdf8;border:1px solid #e8d8b0;border-radius:8px;padding:16px;font-size:14px;line-height:1.7;color:#333;'>
                    $safe_question
                </div>
            </div>
            $tbl_spec
            $opts_html"
        . $email_wrap_close;

        $sent_admin = wp_mail($to_admin, $subject_admin, $message_admin, $headers_base, $attachments);

        if ($email && $email !== '-') {
            $message_customer = $email_wrap_open . "
                <div style='text-align:center;padding:10px 0 30px;'>
                    <h2 style='color:#333;font-size:20px;margin-bottom:12px;'>ได้รับข้อความของคุณแล้ว</h2>
                    <p style='color:#666;font-size:15px;line-height:1.7;'>
                        ทีมงาน Deskspace ได้รับข้อความจาก <strong>{$display_name}</strong> เรียบร้อยแล้ว<br>
                        จะติดต่อกลับเพื่อชี้แจงข้อสงสัยทาง <strong>Line หรืออีเมล</strong> โดยเร็วที่สุดค่ะ
                    </p>
                    <div style='background:#fffdf8;border:1px solid #e8d8b0;border-radius:8px;padding:14px;margin:20px auto;max-width:420px;text-align:left;'>
                        <p style='font-size:11px;color:#b69652;letter-spacing:0.1em;text-transform:uppercase;margin:0 0 8px;'>ข้อความของคุณ</p>
                        <p style='font-size:13px;color:#555;line-height:1.6;margin:0;'>$safe_question</p>
                    </div>
                </div>
                <div style='background:#f9f7f3;border-radius:8px;padding:15px;text-align:center;font-size:12px;color:#999;'>
                    หากมีข้อสงสัย: team@boxbillion.com | Line: @deskspace
                </div>"
            . $email_wrap_close;

            wp_mail($email, 'Deskspace — ทีมงานได้รับข้อความของคุณแล้ว',
                $message_customer,
                ['Content-Type: text/html; charset=UTF-8', 'From: Deskspace <deskspaceth@gmail.com>']
            );
        }
    }

    // --- Logging ---
    if ($sent_admin) {
        $upload   = wp_upload_dir();
        $base_dir = $upload['basedir'] . '/dslog-deskspace';
        $img_dir  = $base_dir . '/images';
        if (!file_exists($base_dir)) mkdir($base_dir, 0755, true);
        if (!file_exists($img_dir))  mkdir($img_dir,  0755, true);
        $htaccess = $base_dir . '/.htaccess';
        if (!file_exists($htaccess)) file_put_contents($htaccess, "Deny from all\n");

        $log_img_url = '';
        if (!empty($temp_files) && file_exists($temp_files[0])) {
            $log_img_name = 'email_' . date('Ymd_His') . '_' . uniqid() . '.jpg';
            $dest = $img_dir . '/' . $log_img_name;
            if (copy($temp_files[0], $dest)) {
                $log_img_url = $upload['baseurl'] . '/dslog-deskspace/images/' . $log_img_name;
            }
        }

        $privacy_accepted = sanitize_text_field($_POST['privacy_accepted'] ?? '0');

        $log_user_status = sanitize_text_field($_POST['user_status']  ?? ($summary_data['User_Status']  ?? 'Guest'));
        $log_account     = sanitize_text_field($_POST['account_name'] ?? ($summary_data['Account_Name'] ?? 'guest'));

        $log_entry = [
            'log_id'               => uniqid('dsl_email_', true),
            'log_source'           => 'email',
            'บันทึกเมื่อ'            => current_time('mysql'),
            'IP_Address'           => $_SERVER['REMOTE_ADDR'],
            'Intent'               => $intent,
            'User_Status'          => $log_user_status,
            'Account_Name'         => $log_account,
            'Device_Type'          => $summary_data['device_type']  ?? (wp_is_mobile() ? 'Mobile' : 'Desktop'),
            'Traffic_Source'       => $summary_data['traffic_source'] ?? 'Unknown',
            'Privacy_Consent'      => ($privacy_accepted === '1') ? 'Accepted' : 'Not Accepted',
            'Privacy_Consent_Time' => ($privacy_accepted === '1') ? current_time('mysql') : null,
            'ข้อมูลลูกค้า'            => [
                'ชื่อ'         => $name,
                'แพลตฟอร์ม'    => 'Email',
                'เบอร์โทร'      => $tel,
                'Line ID'     => $line_id,
                'Email'       => $email,
                'วันที่เลือก'     => $summary_data['ข้อมูลลูกค้า']['วันที่เลือก'] ?? '-',
            ],
            'คำถาม'                => $question,
            'สเปคโต๊ะ'              => $summary_data['สเปคโต๊ะ']          ?? [],
            'ระยะห่างขาโต๊ะ'        => $summary_data['ระยะห่างขาโต๊ะ']    ?? [],
            'รายละเอียดมุมโต๊ะ'     => $summary_data['รายละเอียดมุมโต๊ะ'] ?? [],
            'รายการ_Options'       => $summary_data['รายการ_Options']    ?? [],
            'จำนวน_Options'        => isset($summary_data['รายการ_Options'])
                                        ? count($summary_data['รายการ_Options']) : 0,
            'Warning_Code'         => $summary_data['Warning_Code']      ?? null,
            'Note_System'          => $summary_data['Note_System']       ?? '',
            'รูปภาพ_Snapshot'       => $log_img_url,
        ];

        file_put_contents(
            $base_dir . '/logs.jsonl',
            json_encode($log_entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    foreach ($temp_files as $f) { if (file_exists($f)) unlink($f); }

    if ($sent_admin) wp_send_json_success();
    else wp_send_json_error('Email sending failed.');
}

// 1. LOGIN HANDLER
add_action('wp_ajax_ds_ajax_login', 'ds_handle_ajax_login');        // สำหรับคนล็อกอินแล้ว (เผื่อไว้)
add_action('wp_ajax_nopriv_ds_ajax_login', 'ds_handle_ajax_login'); // สำหรับคนยังไม่ล็อกอิน
function ds_handle_ajax_login() {
    // ตรวจสอบ Security Nonce
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'ds_auth_nonce')) {
        wp_send_json_error('Security Check Failed');
        wp_die();
    }
    
    $creds = array(
        'user_login'    => sanitize_text_field($_POST['log']),
        'user_password' => $_POST['pwd'],
        'remember'      => (isset($_POST['rememberme']) && $_POST['rememberme'] == 'forever')
    );

    // ใช้ false แทน is_ssl() เพื่อความชัวร์ใน Localhost/Http (ถ้าเว็บเป็น https อยู่แล้วไม่มีปัญหา)
    $user = wp_signon($creds, false); 

    if (is_wp_error($user)) {
        // ส่งข้อความ Error ที่ชัดเจนกลับไป
        wp_send_json_error($user->get_error_message());
    } else {
        wp_send_json_success('Login Success');
    }
    wp_die(); // สำคัญมาก! ต้องปิดการทำงานเสมอ
}

// 2. REGISTER HANDLER
add_action('wp_ajax_nopriv_ds_ajax_register', 'ds_handle_ajax_register');
function ds_handle_ajax_register() {
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'ds_auth_nonce')) {
        wp_send_json_error('Security Check Failed');
        wp_die();
    }

    // Honeypot Check
    if (!empty($_POST['ds_hp'])) {
        wp_send_json_error('Bot detected');
        wp_die();
    }

    $username = sanitize_user($_POST['reg_user']);
    $email    = sanitize_email($_POST['reg_email']);
    $pass1    = $_POST['reg_pass1'];
    $pass2    = $_POST['reg_pass2'];

    if (empty($username) || empty($email) || empty($pass1)) {
        wp_send_json_error('กรุณากรอกข้อมูลให้ครบ');
        wp_die();
    }
    if ($pass1 !== $pass2) {
        wp_send_json_error('รหัสผ่านไม่ตรงกัน');
        wp_die();
    }
    if (username_exists($username) || email_exists($email)) {
        wp_send_json_error('ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้แล้ว');
        wp_die();
    }

    $user_id = wp_create_user($username, $pass1, $email);

    if (is_wp_error($user_id)) {
        wp_send_json_error($user_id->get_error_message());
    } else {
        // Set Role as Customer
        $user = new WP_User($user_id);
        $user->set_role('customer');

        // Auto Login
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        wp_send_json_success('Register Success');
    }
    wp_die(); // สำคัญมาก!
}

// 3. FORGOT PASSWORD HANDLER
add_action('wp_ajax_nopriv_ds_ajax_forgot', 'ds_handle_ajax_forgot');
function ds_handle_ajax_forgot() {
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'ds_auth_nonce')) {
        wp_send_json_error('Security Check Failed');
        wp_die();
    }
    
    if (!empty($_POST['ds_hp'])) { wp_send_json_error('Bot detected'); wp_die(); }

    $login = sanitize_text_field($_POST['forgot_login']);
    $user_data = get_user_by('email', $login);
    if (!$user_data) $user_data = get_user_by('login', $login);

    if (!$user_data) {
        wp_send_json_error('ไม่พบข้อมูลผู้ใช้นี้');
    } else {
        $result = retrieve_password($user_data->user_login);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Sent');
        }
    }
    wp_die(); // สำคัญมาก!
}

// 4. LOGOUT HANDLER (เพิ่มต่อท้ายส่วนเดิม)
add_action('wp_ajax_ds_ajax_logout', 'ds_handle_ajax_logout');
function ds_handle_ajax_logout() {
    // ตรวจสอบความปลอดภัย
    check_ajax_referer('ds_auth_nonce', 'security');
    
    // สั่ง Logout ทันที
    wp_logout();
    
    // ส่งค่ากลับไปบอก JS ว่าสำเร็จ
    wp_send_json_success();
    wp_die();
}

// 5. ENQUEUE SCRIPTS & LOCALIZE DATA (เพิ่มส่วนนี้ใน functions.php)
add_action('wp_enqueue_scripts', 'ds_enqueue_auth_scripts');
function ds_enqueue_auth_scripts() {
    // ลงทะเบียน script เปล่าๆ เพื่อใช้เป็น handle ในการส่งค่า
    // (หากคุณมีไฟล์ .js หลักของเว็บ ให้เปลี่ยน 'ds-auth-js' เป็นชื่อ handle ของไฟล์นั้นแทน)
    wp_register_script('ds-auth-js', '', [], '', true);
    wp_enqueue_script('ds-auth-js');

    // เตรียมข้อมูล User
    $current_user = wp_get_current_user();
    $user_data = array();
    
    if ( is_user_logged_in() ) {
        // แปลง Object User Data เป็น Array
        $user_data = (array) $current_user->data;
        // เพิ่ม Roles เข้าไป
        $user_data['roles'] = $current_user->roles;
    }

    // ส่งค่า PHP ไปเป็นตัวแปร JavaScript ชื่อ 'ds_auth_vars'
    wp_localize_script('ds-auth-js', 'ds_auth_vars', array(
        'ajax_url'     => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('ds_auth_nonce'), // Nonce จะถูกสร้างใหม่ตรงนี้
        'is_logged'    => is_user_logged_in(),
        'current_user' => $user_data,
        'logout_url'   => wp_logout_url(home_url()),
    ));
	wp_localize_script('ds-auth-js', 'dpb_ajax', array(
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('dpb_security_nonce_v4')
    ));
}

add_action('wp_ajax_dpb_meta', 'deskspace_proposal_builder_fetch_meta');
add_action('wp_ajax_nopriv_dpb_meta', 'deskspace_proposal_builder_fetch_meta');
function deskspace_proposal_builder_fetch_meta()
{
    if (!defined('EC_DRAW_API_URL')) {
        wp_send_json_error(['message' => 'API URL is not configured'], 500);
    }
    $cache_key = 'deskspace_proposal_builder_meta_v1';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json($cached);
    }
    $remote_url = add_query_arg(
        [
            'action' => 'meta',
            'token'  => defined('EC_DRAW_API_TOKEN') ? EC_DRAW_API_TOKEN : '',
        ],
        EC_DRAW_API_URL
    );
    $response = wp_remote_get(
        $remote_url,
        [
            'timeout' => 15,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => defined('EC_DRAW_API_TOKEN') ? 'Bearer ' . EC_DRAW_API_TOKEN : '',
            ],
        ]
    );
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()], 502);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300) {
        $message = sprintf('Remote API responded with HTTP %d', $code ?: 0);
        wp_send_json_error(['message' => $message], $code ?: 500);
    }
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        wp_send_json_error(['message' => 'Remote API returned an invalid payload'], 500);
    }
    set_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);
    wp_send_json($data);
}

// 1. แยกส่วนเช็ค Admin ออกมาไว้ด้านนอก เพื่อให้ทำงานทันก่อนหน้าเว็บโหลดส่วนหัว
add_action('wp_head', function() {
    ?>
    <script type="text/javascript">
        window.wpData = window.wpData || {};
        window.wpData.isAdmin = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
    </script>
    <?php
});

// 2. ตัว Shortcode ของคุณ
add_shortcode('deskspace_proposal_builder10', function () {
    ob_start(); 
    
    // --- [ส่วนที่ 1] ตรวจสอบสิทธิ์ ---
    $current_user_dpb = wp_get_current_user();
    // ตรวจสอบว่าเป็น admin และ user login คือ 'kantapit'
    $is_kantapit_admin = ($current_user_dpb->exists() && $current_user_dpb->user_login === 'kantapit' && current_user_can('administrator'));
    
    $canShow3D_PHP = $is_kantapit_admin; 
    

    $admin_display_style = current_user_can('administrator') ? '' : 'style="display:none !important;"';
    
    ?>
	
<div id="preload">
  <div class="wrap">
<div class="ring"></div>
    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" class="deskspace-logo-svg">
      <path d="M0.5,20.5L6,25c0.4,0.3,1,0,1-0.5v-8.4L0.5,12V20.5z"/>
      <path d="M18,16.2l10.6,6.3c0.5,0.3,1.1-0.1,1.1-0.6v-10L18,13.8V16.2z"/>
      <path d="M29.7,9.8L18,4.2C17.6,4,17.2,4,16.8,4l-6.7,1.1C9.6,5.2,9.5,5.8,9.9,6l3.1,1.7c0.3,0.1,0.2,0.5-0.1,0.5 C10.4,8.5,3.4,9.4,0.6,9.6C0,9.7-0.2,10.5,0.3,10.9l6.4,3.9c0.2,0.1,0.4,0.1,0.6,0.1l22.2-4C30.1,10.8,30.2,10.1,29.7,9.8z"/>
    </svg>
  </div>
</div>

<header class="ds-header">
    <a href="https://www.deskspace.in.th/" class="ds-header-logo">
        <img src="https://www.deskspace.in.th/wp-content/uploads/2022/05/logo.png" alt="DeskSpace Logo">
    </a>

    <?php 
    $ds_is_logged = is_user_logged_in(); 
    $ds_current_user = wp_get_current_user();
    ?>
    
    <div class="ds-header-actions" style="display:flex; align-items:center; gap:10px; margin-left:auto; margin-right:0px;">


<div id="ds-header-lang-toggle" class="ds-header-lang-container">
    <div class="ds-lang-slider"></div>
    <button class="ds-lang-btn" data-lang="th">TH</button>
    <button class="ds-lang-btn" data-lang="en">EN</button>
</div>

<div id="google_translate_element" style="display:none;visibility:hidden;position:absolute;"></div>

<button type="button" id="dpb-mobile-quote-btn" class="dpb-trigger-btn-mobile">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
  <span>ขอใบเสนอราคา</span>
</button>

	   <button type="button" class="dpb-user-btn" onclick="dsAuthModal.open()" title="<?php echo $ds_is_logged ? 'ข้อมูลส่วนตัว' : 'เข้าสู่ระบบ / สมัครสมาชิก'; ?>">
            <?php if ($ds_is_logged) : ?>
                <span class="ds-user-avatar">
                    <?php echo get_avatar($ds_current_user->ID, 24); ?>
                </span>
            <?php else : ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 11C14.2091 11 16 9.20914 16 7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7C8 9.20914 9.79086 11 12 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            <?php endif; ?>
        </button>

        
<button type="button" class="dpb-trigger-btn" onclick="dpbOpenModal()">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
  <span>ขอใบเสนอราคา / ติดต่อเรา</span>
</button>

    </div>
</header>

<div class="dpb-wrap dpb-fullscreen-mode">
    <div class="dpb-stage-panel">
        <div class="dpb-card-canvas">
            <div class="dpb-preview">
                <div class="dpb-canvas-wrap">
                    <canvas id="dpb-canvas" width="1300" height="1202"></canvas>
                    <button type="button" class="dpb-btn-popup" title="ขยายภาพ">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"></path></svg>
                    </button>
					

</div>

<div id="dpb-result" class="dpb-help">
    <div id="dpb-actions-home"></div>
    <div class="dpb-actions">
        <button id="dpb-download" class="dpb-btn dpb-btn-ghost" title="บันทึกเป็น PNG">
            <i class="fas fa-download"></i> <span>บันทึกภาพ</span>
        </button>

        <button id="dpb-theme-btn" class="dpb-btn dpb-btn-ghost">
            <i class="fas fa-paint-brush"></i> <span>ธีม</span>
        </button>

        <div class="checkbox-wrapper-34 dpb-switch-legs">
            <input class="tgl tgl-ios" id="dpb-show-legs" type="checkbox" checked="">
            <label class="tgl-btn" for="dpb-show-legs" data-on="แสดงขา" data-off="ซ่อนขา"></label>
        </div>
		<button type="button" id="dpb-hidden-save-btn" style="display:none !important;" aria-hidden="true" title="Force Save Log"></button>

        <button id="dpb-preview-btn" class="dpb-btn" title="Refresh">ดูตัวอย่าง</button>
    </div>
    
    <span id="dpb-msg"></span>
</div>
            </div>
        </div>
    </div>

  <div class="dpb-sidebar-panel">
    <div class="dpb-card">
        <div class="dpb-body">
				<h2>Desk Configurator</h2>
            <div class="dpb-row">
                <div>
                    <select id="dpb-type" style="display:none">
                      <option value="custom">Custom Desk<br> (Dual Motor)</option>
                      <option value="custom_single">Custom Desk<br> (Single Motor)</option>
                      <option value="custom_manual">Custom Desk<br> (Manual)</option>
                      <option value="single">Custom Desk<br> (Single leg)</option>
                      <option value="l2">Custom L-Desk<br> (2 Legs)</option>
                      <option value="l3">Custom L-Desk<br> (3 Legs)</option>
					  <option value="custom_workspace">Dual Workspace</option>
                    </select>
                    <div id="dpb-type-tiles" class="dpb-type-tiles" aria-label="เลือกประเภทโต๊ะ"></div>
                </div>
            </div>
            
            <h3>ขนาดโต๊ะ</h3>
            <div class="dpb-row-3">
                <div><label>ความกว้าง (cm)</label><input id="dpb-mw" type="number" value="60" inputmode="decimal"></div>
                <div><label>ความยาว (cm)</label><input id="dpb-ml" type="number" value="160" inputmode="decimal"></div>
            </div>
            
            <div id="dpb-ldesk-extra" style="display:none">
                <div class="dpb-row-3">
                    <div><label>ความกว้างด้าน L (cm)</label><input id="dpb-aw" type="number" value="120" inputmode="decimal"></div>
                    <div><label>ความยาวด้าน L (cm)</label><input id="dpb-al" type="number" value="60"  inputmode="decimal"></div>
                </div>
            </div>

            <h3>สีท็อปโต๊ะ</h3>
            <div id="dpb-top-color-tiles" class="dpb-color-tiles"></div>
            <select id="dpb-top-color" style="display:none"></select>

            <div class="dpb-row">
                 <div>
				      <h3>ขาโต๊ะ</h3>
                    <select id="dpb-legs" style="display:none"></select> <div id="dpb-legs-tiles" class="dpb-type-tiles" aria-label="เลือกโครงขา"></div>
                    <div class="dpb-legs-head" style="justify-content: center;display:flex;align-items:center;gap:8px">
				<button type="button" id="dpb-leggap-toggle" class="dpb-link-btn" aria-expanded="false">
            แก้ไขระยะห่างขาโต๊ะ <i class="dpb-caret" aria-hidden="true"></i>
          </button>
        </div>
                    <div id="dpb-leggap-fields" class="dpb-leggap" aria-hidden="true">
                        <div class="dpb-row-2">
                            <div>
                                <label id="dpb-gapA-label" for="dpb-gapA">ขาซ้าย (cm)</label>
                                <input id="dpb-gapA" type="number" min="5" step="1" value="5" inputmode="decimal" />
                            </div>
                            <div>
                                <label id="dpb-gapB-label" for="dpb-gapB">ขาขวา (cm)</label>
                                <input id="dpb-gapB" type="number" min="5" step="1" value="5" inputmode="decimal" />
                            </div>
                        </div>
                        <div class="dpb-hintbar">
                            <button type="button" id="dpb-gap-reset"  class="dpb-linkbtn">Reset</button>
                            <button type="button" id="dpb-gap-center" class="dpb-linkbtn">Center</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dpb-row">
                <div>
                    <h3>ขอบมุมโต๊ะ</h3>
                    <select id="dpb-edge" style="display:none">
                        <option value="rounded" selected>มุมมน</option>
                        <option value="square">มุมเหลี่ยม</option>
                    </select>
                    <input type="hidden" id="dpb-solid-trim" value="untrim">
                    <div id="dpb-edge-tiles" class="dpb-type-tiles" aria-label="เลือกมุมโต๊ะ"></div>
                </div>
            </div>

            <div id="dpb-l-only" style="display:none">
                <div class="dpb-row">
                    <div>
                        <label>ทิศตัว L</label>
                        <select id="dpb-aside" style="display:none">
                            <option value="left">ซ้าย</option>
                            <option value="right">ขวา</option>
                        </select>
                        <div id="dpb-aside-tiles" class="dpb-type-tiles" aria-label="เลือกทิศตัว L"></div>
                    </div>
                </div>
            </div>
            
            <div id="dpb-r-rect" class="dpb-row-3" style="display:none">
                <div><label>มุมบนซ้าย</label><input id="r_rect_tl" type="number" value="50"></div>
                <div><label>มุมบนขวา</label><input id="r_rect_tr" type="number" value="50"></div>
                <div><label>มุมล่างซ้าย</label><input id="r_rect_bl" type="number" value="50"></div>
                <div><label>มุมล่างขวา</label><input id="r_rect_br" type="number" value="50"></div>
            </div>
            <div id="dpb-r-ldesk" style="display:none">
                <div class="dpb-row-3">
        <div><label>มุมบนซ้าย (mm)</label><input id="ld_r_tl" type="number" value="50" inputmode="numeric"></div>
        <div><label>มุมบนขวา (mm)</label><input id="ld_r_tr" type="number" value="50" inputmode="numeric"></div>
        <div><label>มุมด้านใน (mm)</label><input id="dpb-rInner" type="number" value="150" inputmode="numeric"></div>
        <div><label>มุมล่างซ้าย (mm)</label><input id="ld_r_step" type="number" value="50"></div>
        <div><label>มุมล่างขวา (mm)</label><input id="ld_r_br" type="number" value="50"></div>
        <div><label>มุม L ล่างซ้าย (mm)</label><input id="ld_r_armbl" type="number" value="50"></div>
        <div><label>มุม L ล่างขวา (mm)</label><input id="ld_r_armbr" type="number" value="50"></div>
                </div>
            </div>
        </div>
    </div>
									
  <div class="dpb-card">
        <h3>ข้อมูลลูกค้า</h3>
        <div class="dpb-body" id="dpb-form">
            <div class="dpb-row">
                <div><label>ชื่อลูกค้า / โครงการ</label><input id="dpb-customer" placeholder="ระบุชื่อ..." /></div>
				
                <div class="dpb-platforms" id="dpb-platforms-admin" <?php echo $admin_display_style; ?>>
                        <label>Platform</label>
                        <select id="dpb-platforms">
                            <option value="">เลือก Platform</option>
                            <option>Facebook</option><option>Line</option><option>Shopee</option><option>Lazada</option>
                            <option>Central</option><option>The Mall</option><option>HomePro</option><option>NocNoc</option>
                            <option>Tiktok</option><option>Shop24</option><option>Mercular</option><option>Betrend</option>
                            <option>Gump</option><option>หน้าร้าน</option><option>Website(DeskSpace)</option>
                        </select>
                </div>
				
            </div>
            <div class="dpb-row">
                <div><label>วันที่</label><input id="dpb-date" type="date"></div>
                <div style="display:none;"><input id="dpb-filename"></div>
            </div>
        </div>
    </div>
									
    <div class="dpb-card">
        <h3>ตัวเลือกเสริม (Options)</h3>
        <div class="dpb-body-option">
            <div id="dpb-opt-list" class="dpb-opt-grid"></div>
            <div class="dpb-sep" style="height:1px;background:var(--line);margin:10px 0"></div>
            <p class="dpb-help" style="text-align:left;"></p>
        </div>
    </div>

  
    
</div>

</div>
<div id="dpb-theme-backdrop" class="dpb-theme-backdrop"></div>
<div id="dpb-theme-panel" class="dpb-theme-panel" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="dpb-theme-panel__inner">
      <header class="dpb-cart-header">
       <button type="button" id="dpb-theme-close" class="dpb-cart-back" aria-label="ย้อนกลับ">
         <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 6l-6 6 6 6" stroke="#111827" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
       </button>
       <div class="dpb-cart-title">ตั้งค่าการแสดงผล </div>

    </header>
    <div class="dpb-theme-body">
      <div class="dpb-row">
        <div>
          <label>สีพื้นหลัง/Background</label>
          <div class="dpb-color-group" id="dpb-bg">
            <button type="button" data-value="#ffffff" style="--c:#ffffff"></button>
            <button type="button" data-value="#f3f4f6" style="--c:#f3f4f6"></button>
            <button type="button" data-value="#a9a9a9" style="--c:#a9a9a9"></button>
            <button type="button" data-value="#212121" style="--c:#212121"></button>
			<button type="button" data-value="rgba(0,0,0,0)" style="--c:url('https://www.deskspace.in.th/wp-content/uploads/2025/12/transparent.png')"></button>
          </div>
        </div>
      </div>
		<div class="dpb-row">
        <div>
			 <label>สีข้อความ/เส้น "ในโต๊ะ"</label>
          <div class="dpb-color-group" id="dpb-color-in">
            <button type="button" data-value="#000000" style="--c:#000000"></button>
            <button type="button" data-value="#ffffff" style="--c:#ffffff"></button>
          </div>
        </div>
      </div>
      <div class="dpb-row">
        <div>
          <label>สีข้อความ/เส้น "นอกโต๊ะ"</label>
          <div class="dpb-color-group" id="dpb-color-out">
            <button type="button" data-value="#000000" style="--c:#000000"></button>
            <button type="button" data-value="#ffffff" style="--c:#ffffff"></button>
          </div>
        </div>
      </div>
    </div>
    <footer class="dpb-theme-footer">
      <button type="button" id="dpb-theme-confirm" class="dpb-btn">ยืนยัน</button>
    </footer>
  </div>
</div>
<div id="dpb-cart-backdrop" class="dpb-cart-backdrop"></div>
<div id="dpb-cart-panel" class="dpb-cart-panel" role="dialog" aria-modal="true" aria-labelledby="dpb-cart-title" aria-hidden="true">
  <div class="dpb-cart-panel__inner">
    <header class="dpb-cart-header">
      <button type="button" id="dpb-cart-close-mobile" class="dpb-cart-back" aria-label="ย้อนกลับ">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M15 6l-6 6 6 6" stroke="#111827" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <button type="button" id="dpb-cart-close-desktop" class="dpb-cart-close" aria-label="ปิด">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M6 6l12 12M18 6L6 18" stroke="#111827" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <div class="dpb-cart-title" id="dpb-cart-title">ตั้งค่า Option</div>
      <div class="dpb-cart-header-actions">
        <button type="button" id="dpb-cart-clear" class="dpb-cart-clear" aria-label="ลบ Option ทั้งหมด">
<svg width="18" height="18" viewBox="0 0 520 520" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M402.06,53.33H339.77l-3.71-8.12C332.32,37,324.54,20,305.08,20H206.61c-19.35,0-27,16.86-30.68,25l-3.8,8.37H109.94a62.11,62.11,0,0,0-62,62,12,12,0,0,0,12,12H452.09a12,12,0,0,0,12-12A62.11,62.11,0,0,0,402.06,53.33Z" fill="#111827" fill-rule="evenodd"/>
  <defs>
    <mask id="bin-holes" maskUnits="userSpaceOnUse">
      <rect x="96.75" y="143.36" width="318.49" height="348" rx="24" ry="24" fill="#fff"/>
      <rect class="hole" x="165" y="235" width="36" height="210" rx="28" ry="28" fill="#000"/> 
      <rect class="hole" x="299" y="235" width="36" height="210" rx="28" ry="28" fill="#000"/> 
    </mask>
  </defs>
  <rect x="96.75" y="143.36" width="318.49" height="348" rx="24" ry="24" fill="#111827" mask="url(#bin-holes)"/>
</svg>
        </button>
      </div>
    </header>
    <div id="dpb-cart-empty" class="dpb-cart-empty">คุณยังไม่ได้เลือก Option</div>
    <div id="dpb-cart-body" class="dpb-cart-body"></div>
    <div class="dpb-cart-footer">
      <button type="button" id="dpb-cart-confirm" class="dpb-cart-confirm">ยืนยัน</button>
    </div>
  </div>
</div>

<div id="dpb-confirm" class="dpb-confirm" role="alertdialog" aria-modal="true" aria-hidden="true">
  <div class="dpb-confirm__box">
    <div class="dpb-confirm__title">คุณต้องการลบ Option ทั้งหมดใช่หรือไม่</div>
    <div class="dpb-confirm__actions">
      <button type="button" data-confirm="no">ยกเลิก</button>
      <button type="button" data-confirm="yes">ยืนยัน</button>
    </div>
  </div>
</div>

<div id="dpb-variant-modal" class="dpb-modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="dpb-modal__backdrop" id="dpb-variant-backdrop"></div>
  <div class="dpb-modal__panel" role="document">
    <button type="button" class="dpb-modal__close" id="dpb-variant-close" aria-label="ปิด">✕</button>
    <div class="dpb-modal__header">
      <div class="dpb-modal__thumb"><img id="dpb-variant-thumb" alt=""></div>
      <div class="dpb-modal__title-group"></div>
    </div>
    <div class="dpb-modal__body"></div>
    <div class="dpb-modal__footer">
      <button type="button" class="dpb-btn dpb-btn-primary" id="dpb-variant-confirm">ยืนยัน</button>
    </div>
  </div>
</div>

<div id="dpb-remove-confirm" class="dpb-modal dpb-mini-confirm" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="dpb-modal__backdrop" id="dpb-remove-confirm-backdrop"></div>
  <div class="dpb-modal__panel dpb-mini-confirm__panel" role="document" aria-labelledby="dpb-remove-confirm-title">
    <div class="dpb-modal__body dpb-mini-confirm__body">
      <div id="dpb-remove-confirm-title" class="dpb-mini-confirm__title">
        ต้องการลบรายการนี้ออกจากตะกร้าหรือไม่?
      </div>
      <div class="dpb-mini-confirm__actions">
        <button type="button" class="dpb-btn dpb-btn-ghost dpb-mini-confirm__no">ยกเลิก</button>
        <button type="button" class="dpb-btn dpb-btn-danger dpb-mini-confirm__yes">ยืนยัน</button>
      </div>
    </div>
  </div>
</div>

<div class="dpb-floating-cart" aria-live="polite">
    <div class="dpb-cart-tooltip">
        ตั้งค่าตำแหน่ง Options
        <div class="dpb-tooltip-arrow"></div>
    </div>
    <button type="button" id="dpb-cart-button" class="dpb-cart-fab is-empty" aria-haspopup="dialog" aria-expanded="false">
        <span class="dpb-cart-icon">
            <svg width="30" height="30" viewBox="0 0 473 473" xmlns="http://www.w3.org/2000/svg" fill="#fff">
                <path d="M472.614,264.846v-57.072l-51.8-8.824c-4.879-24.233-14.438-46.753-27.654-66.645l30.42-42.911l-40.358-40.358
l-42.911,30.421C320.419,66.24,297.901,56.679,273.667,51.8L264.843,0h-57.074l-8.82,51.8
c-24.234,4.88-46.754,14.44-66.647,27.657L89.39,49.036L49.034,89.394l30.421,42.911c-13.216,19.892-22.776,42.411-27.657,66.645
l-51.797,8.824v57.072l51.797,8.822c4.881,24.233,14.441,46.755,27.657,66.644l-30.421,42.913l40.356,40.356l42.914-30.421
c19.889,13.216,42.409,22.776,66.644,27.656l8.82,51.799h57.074l8.824-51.799c24.234-4.88,46.752-14.439,66.644-27.656
l42.911,30.421l40.358-40.356l-30.42-42.913c13.215-19.889,22.775-42.41,27.654-66.644L472.614,264.846z M236.308,333.1
c-53.458,0-96.794-43.334-96.794-96.793c0-53.457,43.336-96.792,96.794-96.792c53.457,0,96.793,43.335,96.793,96.792
C333.1,289.766,289.764,333.1,236.308,333.1z"/>
            </svg>
        </span>
        <span id="dpb-cart-count" class="dpb-cart-badge" style="display: none;">0</span>
    </button>
</div>
<div class="dpb-sticky-footer">
	<div class="dpb-footer-tools">
		<button type="button" class="dpb-tool-btn" id="dpb-footer-download">
		<i class="fas fa-download"></i>
		<span>บันทึก</span>
		</button>
		<button type="button" class="dpb-tool-btn" id="dpb-footer-theme">
		<i class="fas fa-paint-brush"></i>
		<span>ธีม</span>
		</button>
		<div class="dpb-tool-item dpb-tool-legs-wrap">
			<div class="checkbox-wrapper-34 dpb-switch-legs">
			<input class="tgl tgl-ios" id="dpb-show-legs-footer" type="checkbox" checked>
			<label class="tgl-btn" for="dpb-show-legs-footer" data-on="แสดงขา" data-off="ซ่อนขา"></label>
		</div>
    </div>
		<button type="button" id="dpb-footer-cart-btn" class="dpb-footer-main-btn">
		<div class="dpb-footer-cart-icon">
			<svg width="24" height="24" viewBox="0 0 473 473" fill="#fff">
			<path d="M472.614,264.846v-57.072l-51.8-8.824c-4.879-24.233-14.438-46.753-27.654-66.645l30.42-42.911l-40.358-40.358 l-42.911,30.421C320.419,66.24,297.901,56.679,273.667,51.8L264.843,0h-57.074l-8.82,51.8 c-24.234,4.88-46.754,14.44-66.647,27.657L89.39,49.036L49.034,89.394l30.421,42.911c-13.216,19.892-22.776,42.411-27.657,66.645 l-51.797,8.824v57.072l51.797,8.822c4.881,24.233,14.441,46.755,27.657,66.644l-30.421,42.913l40.356,40.356l42.914-30.421 c19.889,13.216,42.409,22.776,66.644,27.656l8.82,51.799h57.074l8.824-51.799c24.234-4.88,46.752-14.439,66.644-27.656 l42.911,30.421l40.358-40.356l-30.42-42.913c13.215-19.889,22.775-42.41,27.654-66.644L472.614,264.846z M236.308,333.1 c-53.458,0-96.794-43.334-96.794-96.793c0-53.457,43.336-96.792,96.794-96.792c53.457,0,96.793,43.335,96.793,96.792 C333.1,289.766,289.764,333.1,236.308,333.1z"></path>
			</svg>
			<span id="dpb-footer-count" class="dpb-footer-badge">0</span>
		</div>
    <span class="dpb-footer-label">ตั้งค่าตัวเลือก</span>
	</button>
	</div>
</div>


      <!-- ========================
          Contact Form
           ======================== -->

<div id="dpbModal" class="dpb-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dpbModalTitle">
  <div class="dpb-modal-box">

    <!-- HEADER -->
    <div class="dpb-modal-header">
      <div class="dpb-header-left">
        <h2 class="dpb-modal-title" id="dpbModalTitle">ติดต่อเรา</h2>
        <div class="dpb-progress" id="dpbProgress">
          <div class="dpb-progress-dot active" id="dpbDot1"></div>
          <div class="dpb-progress-dot" id="dpbDot2"></div>
        </div>
      </div>
      <button class="dpb-close-btn" onclick="dpbCloseModal()" aria-label="ปิด">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- SCROLLABLE AREA -->
    <div class="dpb-modal-scroll">

      <!-- ========================
           STEP 1: INTENT SELECTOR
           ======================== -->
      <div id="dpbIntentView" class="dpb-view">
        <!-- Design Preview -->
        <div class="dpb-preview-wrap">
          <img id="dpb_preview_img" src="" alt="Design Preview" style="display:none;">
          <span id="dpb_preview_placeholder" style="font-size:12px; color:#9a9590; letter-spacing:0.06em;">กำลังโหลดภาพแบบ...</span>
        </div>

        <!-- Intent Cards -->
        <div class="dpb-intent-cards">
          <button class="dpb-intent-card" onclick="dpbSelectIntent('quote')" type="button">
            <div class="dpb-intent-icon">
 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
  <path d="M14 2v6h6"></path>
  <path d="M16 13H8"></path>
  <path d="M16 17H8"></path>
  <path d="M10 9H8"></path>
</svg>            
            </div>
            <div>
              <div class="dpb-intent-label">ขอใบเสนอราคา</div>
              <div class="dpb-intent-desc">รับใบเสนอราคาจากแบบที่คุณออกแบบไว้</div>
            </div>
         
          </button>

          <button class="dpb-intent-card" onclick="dpbSelectIntent('inquiry')" type="button">
            <div class="dpb-intent-icon">

<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
  <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path>
  <path d="M8 12h.01"></path>
  <path d="M12 12h.01"></path>
  <path d="M16 12h.01"></path>
</svg>
            </div>
            <div>
              <div class="dpb-intent-label">สอบถามข้อมูล</div>
              <div class="dpb-intent-desc">สอบถามรายละเอียดของแบบโต๊ะเพิ่มเติม</div>
            </div>
            
          </button>
        </div>
      </div>

      <!-- ========================
           STEP 2A: QUOTE FORM
           ======================== -->
<div id="dpbQuoteView" class="dpb-form-view dpb-view" style="display:none;">
        <div class="dpb-section-label">ข้อมูลติดต่อ</div>
        <div class="dpb-grid-row">
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_q_name">ชื่อ / บริษัท <span class="dpb-req">*</span></label>
            <input type="text" id="dpb_q_name" class="dpb-input" placeholder="Name or Company">
            <span class="dpb-error-msg">กรุณากรอกชื่อ</span>
          </div>
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_q_email">อีเมล <span class="dpb-req">*</span></label>
            <input type="email" id="dpb_q_email" class="dpb-input" placeholder="name@example.com">
            <span class="dpb-error-msg">กรุณากรอกอีเมล</span>
          </div>
        </div>
        <div class="dpb-grid-row">
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_q_tel">เบอร์โทร <span class="dpb-req">*</span></label>
            <input type="tel" id="dpb_q_tel" class="dpb-input" placeholder="08x-xxx-xxxx">
            <span class="dpb-error-msg">กรุณากรอกเบอร์โทร</span>
          </div>
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_q_line">Line <span class="dpb-opt">(ไม่บังคับ)</span></label>
            <input type="text" id="dpb_q_line" class="dpb-input" placeholder="Line ID or Number">
          </div>
        </div>

 
        <div class="dpb-form-group">
          <label class="dpb-label" for="dpb_q_note">รายละเอียดที่ต้องการแจ้งเพิ่มเติม <span class="dpb-opt">(ไม่บังคับ)</span></label>
          <textarea id="dpb_q_note" class="dpb-textarea" placeholder="พิมพ์รายละเอียดเพิ่มเติมที่นี่ (ถ้ามี)..."></textarea>
        </div>
      </div>
	  
      <!-- ========================
           STEP 2B: INQUIRY FORM
           ======================== -->
      <div id="dpbInquiryView" class="dpb-form-view dpb-view" style="display:none;">
        <div class="dpb-section-label">Contact Information</div>
        <div class="dpb-grid-row">
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_i_name">ชื่อ / บริษัท <span class="dpb-req">*</span></label>
            <input type="text" id="dpb_i_name" class="dpb-input" placeholder="Name or Company">
            <span class="dpb-error-msg">กรุณากรอกชื่อ</span>
          </div>
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_i_email">อีเมล <span class="dpb-req">*</span></label>
            <input type="email" id="dpb_i_email" class="dpb-input" placeholder="name@example.com">
            <span class="dpb-error-msg">กรุณากรอกอีเมล</span>
          </div>
        </div>
        <div class="dpb-grid-row">
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_i_tel">เบอร์โทร <span class="dpb-req">*</span></label>
            <input type="tel" id="dpb_i_tel" class="dpb-input" placeholder="08x-xxx-xxxx">
            <span class="dpb-error-msg">กรุณากรอกเบอร์โทร</span>
          </div>
          <div class="dpb-form-group">
            <label class="dpb-label" for="dpb_i_line">Line <span class="dpb-opt">(ไม่บังคับ)</span></label>
            <input type="text" id="dpb_i_line" class="dpb-input" placeholder="Line ID or Number">
          </div>
        </div>

        <div class="dpb-section-label" style="margin-top:8px;">Message</div>
        <div class="dpb-form-group">
          <label class="dpb-label" for="dpb_i_question">รายละเอียดที่ต้องการสอบถาม <span class="dpb-req">*</span></label>
          <textarea id="dpb_i_question" class="dpb-textarea" placeholder="พิมพ์รายละเอียดสินค้าที่สนใจ หรือข้อสงสัยของคุณที่นี่..."></textarea>
          <span class="dpb-error-msg">กรุณากรอกข้อความ</span>
        </div>
        <div style="height:24px;"></div>
      </div>

<!-- ========================
           SUCCESS VIEW
           ======================== -->
      <div id="dpbSuccessView" style="display:none;">
        <!-- Burst animation wrapper -->
        <div class="dpb-success-burst">
          <div class="dpb-success-burst-ring"></div>
          <div class="dpb-success-burst-ring"></div>
          <!-- Spark particles -->
          <div class="dpb-spark"></div>
          <div class="dpb-spark"></div>
          <div class="dpb-spark"></div>
          <div class="dpb-spark"></div>
          <div class="dpb-spark"></div>
          <div class="dpb-spark"></div>
          <!-- Main icon -->
          <div class="dpb-success-icon-wrap">
            <svg class="dpb-success-svg" width="100" height="100" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
  <path class="dpb-check-stroke" d="M30 50 L43 63 L70 35" stroke="#b69652" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
          </div>
        </div>

        <h3 class="dpb-success-title">ส่งข้อมูลเรียบร้อยแล้ว</h3>
        <div class="dpb-success-divider"></div>
        <p class="dpb-success-body" id="dpbSuccessMsg">
          ทีมงานได้รับข้อมูลของคุณแล้ว<br>และจะติดต่อกลับโดยเร็วที่สุด
        </p>
        <button onclick="dpbCloseModal()" class="dpb-trigger-btn" style="margin: 0 auto; font-size:13px; animation: dpbFadeUp 0.5s 0.9s ease both; opacity:0;" type="button">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <span>ปิดหน้าต่าง</span>
        </button>
      </div>

<!-- ========================
           PRIVACY POLICY VIEW (View 3 — Hidden)
           ======================== -->
      <div id="dpbPrivacyView" style="display:none; flex-direction:column; overflow:hidden; height:100%;">
        <div class="dpb-privacy-scroll-box">
          <h2>Privacy Policy</h2>
          <span class="dpb-prv-brand">นโยบายการคุ้มครองข้อมูลส่วนบุคคล</span>
          <p class="dpb-prv-intro">
            แบรนด์ DeskSpace ภายใต้การดูแลของ บริษัท บ็อกซ์ บิลเลี่ยน จำกัด ("บริษัทฯ") ตระหนักถึงความสำคัญของข้อมูลส่วนบุคคลของคุณ เราจัดทำนโยบายฉบับนี้เพื่อให้คุณมั่นใจว่าข้อมูลของคุณจะได้รับการดูแลและรักษาความปลอดภัยอย่างสูงสุด ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA)
          </p>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">1. วัตถุประสงค์ในการเก็บรวบรวมข้อมูล</div>
            <div class="dpb-prv-section-body">
              บริษัทฯ จะเก็บรวบรวมและใช้ข้อมูลส่วนบุคคลของคุณเฉพาะเท่าที่จำเป็นภายใต้วัตถุประสงค์ดังต่อไปนี้:
              <ul class="dpb-prv-list">
                <li>ติดต่อกลับ ให้คำปรึกษา และตอบข้อซักถามเกี่ยวกับสินค้าและบริการ</li>
                <li>จัดทำและนำส่งใบเสนอราคาตามที่คุณร้องขอ</li>
                <li>ดำเนินการรับคำสั่งซื้อและประสานงานการจัดส่งสินค้า</li>
                <li>การบริการหลังการขายและการรับประกันสินค้า</li>
              </ul>
            </div>
          </div>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">2. ข้อมูลส่วนบุคคลที่เราเก็บรวบรวม</div>
            <div class="dpb-prv-section-body">
              ในการส่งข้อมูลผ่านแบบฟอร์มนี้ บริษัทฯ จะเก็บรวบรวมข้อมูลดังต่อไปนี้:
              <ul class="dpb-prv-list">
                <li>ชื่อ - นามสกุล หรือ ชื่อบริษัท</li>
                <li>หมายเลขโทรศัพท์</li>
                <li>ที่อยู่อีเมล (Email)</li>
                <li>Line ID (ถ้ามี)</li>
                <li>ข้อมูลรายละเอียดสเปคสินค้าที่คุณเลือก</li>
              </ul>
            </div>
          </div>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">3. การเปิดเผยข้อมูลส่วนบุคคล</div>
            <div class="dpb-prv-section-body">
              <div class="dpb-prv-highlight">
                บริษัทฯ ขอยืนยันว่า จะไม่มีการนำข้อมูลส่วนบุคคลของคุณไปขาย แลกเปลี่ยน หรือเผยแพร่ให้กับบุคคลภายนอกโดยเด็ดขาด
              </div>
              ข้อมูลของคุณจะถูกเข้าถึงและประมวลผลโดยทีมงานภายในของบริษัทฯ ที่มีหน้าที่เกี่ยวข้องกับการให้บริการคุณเท่านั้น ยกเว้นในกรณีที่มีความจำเป็นต้องปฏิบัติตามกฎหมาย หรือตามคำสั่งของหน่วยงานรัฐที่มีอำนาจ
            </div>
          </div>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">4. การเก็บรักษาและระยะเวลา</div>
            <div class="dpb-prv-section-body">
              บริษัทฯ จะจัดเก็บข้อมูลส่วนบุคคลของคุณในระบบที่มีมาตรการรักษาความปลอดภัยทางอิเล็กทรอนิกส์ที่ได้มาตรฐาน และจะเก็บรักษาข้อมูลไว้ตามระยะเวลาที่จำเป็นต่อการบรรลุวัตถุประสงค์ข้างต้น หรือจนกว่าคุณจะมีการร้องขอให้ลบข้อมูล
            </div>
          </div>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">5. สิทธิของเจ้าของข้อมูลส่วนบุคคล</div>
            <div class="dpb-prv-section-body">
              ในฐานะเจ้าของข้อมูล คุณมีสิทธิตามกฎหมายดังต่อไปนี้:
              <ul class="dpb-prv-list">
                <li>สิทธิในการขอเข้าถึง ขอรับสำเนา หรือขอแก้ไขข้อมูลให้ถูกต้อง</li>
                <li>สิทธิในการขอให้ระงับการใช้ หรือลบข้อมูลส่วนบุคคลของคุณออกจากระบบ</li>
                <li>สิทธิในการเพิกถอนความยินยอมที่คุณได้ให้ไว้กับบริษัทฯ ได้ตลอดเวลา</li>
              </ul>
            </div>
          </div>

          <div class="dpb-prv-section">
            <div class="dpb-prv-section-title">6. ช่องทางการติดต่อ</div>
            <div class="dpb-prv-section-body">
              หากคุณมีข้อสงสัยเกี่ยวกับนโยบายฉบับนี้ หรือต้องการใช้สิทธิของเจ้าของข้อมูล สามารถติดต่อเราได้ที่:
              <div class="dpb-prv-contact-row" style="margin-top:10px;">
                <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"></path></svg>
                team@boxbillion.com
              </div>
              <div class="dpb-prv-contact-row">
                <svg viewBox="0 0 64 64"><path d="M64 27.487c0-14.32-14.355-25.97-32-25.97S0 13.168 0 27.487c0 12.837 11.384 23.588 26.762 25.62 1.042.225 2.46.688 2.82 1.578.322.81.21 2.076.103 2.894l-.457 2.74c-.14.81-.643 3.164 2.772 1.725s18.428-10.852 25.143-18.58h-.001C61.78 38.38 64 33.218 64 27.487" fill="#b69652"></path><g fill="#fff"><path d="M25.498 20.568h-2.245c-.344 0-.623.28-.623.623v13.943a.62.62 0 0 0 .623.62h2.245a.62.62 0 0 0 .623-.62V21.2c0-.343-.28-.623-.623-.623"></path><path d="M40.948 20.558h-2.244c-.345 0-.624.28-.624.623v8.284l-6.4-8.63a.6.6 0 0 0-.158-.154.62.62 0 0 0-.168-.022h-2.244c-.344 0-.623.28-.623.623V35.13a.62.62 0 0 0 .623.62h2.244c.344 0 .624-.278.624-.62v-8.28l6.397 8.64a.62.62 0 0 0 .203.184.62.62 0 0 0 .168.022h2.244a.62.62 0 0 0 .623-.62V21.2c0-.343-.28-.623-.623-.623"></path><path d="M20.087 32.264h-6.1V21.2c0-.344-.28-.623-.623-.623H11.12c-.344 0-.623.28-.623.623v13.942a.62.62 0 0 0 .174.431.62.62 0 0 0 .43.174h8.968c.344 0 .623-.28.623-.623v-2.245c0-.344-.278-.623-.623-.623"></path><path d="M53.345 20.558h-8.968a.62.62 0 0 0-.432.176.62.62 0 0 0-.173.43v13.943a.62.62 0 0 0 .174.431.62.62 0 0 0 .431.174h8.968c.344 0 .623-.28.623-.623v-2.246c0-.344-.278-.623-.623-.623h-6.098v-2.357h6.098a.62.62 0 0 0 .623-.623V27.04c0-.344-.278-.624-.623-.624h-6.098V24.06h6.098c.344 0 .623-.28.623-.623V21.2c0-.343-.278-.623-.623-.623"></path></g></svg>
                Line Official: @deskspace
              </div>
            </div>
          </div>
        </div>
		<div class="dpb-privacy-header-bar">
          
          <span class="dpb-privacy-view-title" onclick="dpbClosePrivacyView()">ย้อนกลับ</span>
        </div>
      </div>

    </div><!-- end scroll -->

<!-- FOOTER (hidden on intent + success) -->
    <div class="dpb-modal-footer" id="dpbFooter">
      <!-- Privacy Consent Checkbox -->
      <div id="dpbPrivacyConsentRow" style="width:100%; margin-bottom:12px;">
        <div class="dpb-privacy-wrap">
          <input type="checkbox" class="dpb-privacy-checkbox" id="dpbPrivacyCheckbox">
          <label class="dpb-privacy-label" for="dpbPrivacyCheckbox">
            ฉันได้อ่านและยอมรับ
            <button type="button" class="dpb-privacy-link" onclick="dpbOpenPrivacyView()">นโยบายการคุ้มครองข้อมูลส่วนบุคคล</button>
            ของ DeskSpace แล้ว
          </label>
        </div>
      </div>
      <!-- Action Buttons -->
      <div style="display:flex; align-items:center; justify-content:flex-end; gap:10px; width:100%;">
        <button onclick="dpbGoBack()" class="dpb-btn-cancel" type="button">ย้อนกลับ</button>
        <button onclick="dpbSubmitForm()" id="dpbSubmitBtn" class="dpb-btn-submit" type="button" disabled>
          <span class="dpb-btn-text">ยืนยันการส่งข้อมูล</span>
          <div class="dpb-btn-spinner"></div>
        </button>
      </div>
    </div>

  </div><!-- end modal-box -->
</div><!-- end overlay -->


<div id="ds-auth-modal" class="ds-modal" aria-hidden="true">
    <div class="ds-modal-backdrop" onclick="dsAuthModal.close()"></div>
    <div class="ds-modal-panel">
        <button type="button" class="ds-modal-close" onclick="dsAuthModal.close()">✕</button>
        
        <div class="ds-modal-header">
            <h2 id="ds-modal-title">เข้าสู่ระบบ</h2>
            <p id="ds-modal-desc">เข้าสู่ระบบเพื่อบันทึกและจัดการแบบของคุณ</p>
        </div>

        <div class="ds-modal-body">
            
            <div id="ds-view-login" class="ds-view">
                <form id="ds-form-login" onsubmit="dsAuthModal.submitLogin(event)">
                    <div class="ds-input-group">
                        <label>อีเมล หรือ ชื่อผู้ใช้</label>
                        <input type="text" name="log" required placeholder="Email or Username">
                    </div>
                    <div class="ds-input-group">
                        <label>รหัสผ่าน</label>
                        <div class="ds-pwd-wrap">
                            <input type="password" name="pwd" required placeholder="Password">
                            <span class="ds-toggle-eye" onclick="dsAuthModal.togglePwd(this)">👁️</span>
                        </div>
                    </div>
                    <div class="ds-actions">
                        <label class="ds-check"><input type="checkbox" name="rememberme" value="forever"> จดจำฉันไว้</label>
                        <a href="#" onclick="dsAuthModal.switchView('forgot'); return false;">ลืมรหัสผ่าน?</a>
                    </div>
                    <button type="submit" class="ds-btn-primary">เข้าสู่ระบบ</button>
                </form>
                <div class="ds-footer-text">
                    ยังไม่มีบัญชี? <a href="#" onclick="dsAuthModal.switchView('register'); return false;">สมัครสมาชิก</a>
                </div>
            </div>

            <div id="ds-view-register" class="ds-view" style="display:none;">
                <form id="ds-form-register" onsubmit="dsAuthModal.submitRegister(event)">
                    <div class="ds-input-group">
                        <label>ชื่อผู้ใช้ (ภาษาอังกฤษ)</label>
                        <input type="text" name="reg_user" required placeholder="Username">
                    </div>
                    <div class="ds-input-group">
                        <label>อีเมล</label>
                        <input type="email" name="reg_email" required placeholder="your@email.com">
                    </div>
                    <div class="ds-row">
                        <div class="ds-input-group">
                            <label>รหัสผ่าน</label>
                            <div class="ds-pwd-wrap">
                                <input type="password" name="reg_pass1" required placeholder="Password">
                                <span class="ds-toggle-eye" onclick="dsAuthModal.togglePwd(this)">👁️</span>
                            </div>
                        </div>
                        <div class="ds-input-group">
                            <label>ยืนยันรหัสผ่าน</label>
                            <div class="ds-pwd-wrap">
                                <input type="password" name="reg_pass2" required placeholder="Confirm">
                                <span class="ds-toggle-eye" onclick="dsAuthModal.togglePwd(this)">👁️</span>
                            </div>
                        </div>
                    </div>
                    <input type="text" name="ds_hp" style="display:none;" tabindex="-1">
                    <button type="submit" class="ds-btn-primary">สมัครสมาชิก</button>
                </form>
                <div class="ds-footer-text">
                    มีบัญชีอยู่แล้ว? <a href="#" onclick="dsAuthModal.switchView('login'); return false;">เข้าสู่ระบบ</a>
                </div>
            </div>

            <div id="ds-view-forgot" class="ds-view" style="display:none;">
                <form id="ds-form-forgot" onsubmit="dsAuthModal.submitForgot(event)">
                    <div class="ds-input-group">
                        <label>อีเมลที่ใช้สมัคร</label>
                        <input type="text" name="forgot_login" required placeholder="Enter your email">
                    </div>
                    <input type="text" name="ds_hp" style="display:none;" tabindex="-1">
                    <button type="submit" class="ds-btn-primary">ส่งลิงก์รีเซ็ต</button>
                </form>
                <div class="ds-footer-text">
                    <a href="#" onclick="dsAuthModal.switchView('login'); return false;">&larr; กลับหน้าเข้าสู่ระบบ</a>
                </div>
            </div>

            <div id="ds-view-profile" class="ds-view" style="display:none; text-align:center;">
                <div class="ds-profile-avatar">
                    <div class="ds-avatar-placeholder">
                        <?php echo get_avatar(get_current_user_id(), 80); ?>
                    </div>
                </div>
                <h3 id="ds-profile-name" style="margin:15px 0 5px 0; color:#333; font-size:20px;">User</h3>
                <span id="ds-profile-role" style="display: inline-block; background: #f3f4f6; color: #666; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; text-transform: uppercase; margin-bottom: 25px;">Customer</span>
                <button type="button" class="ds-btn-primary" style="background:#dc2626; border-color:#dc2626;" onclick="dsAuthModal.switchView('logout-confirm')">ออกจากระบบ</button>
            </div>
			<div id="ds-view-logout-confirm" class="ds-view" style="display:none; text-align:center;">
    <div class="ds-logout-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
    </div>
    
    <h3 style="margin:10px 0 5px 0; color:#333; font-size:22px;">ออกจากระบบ?</h3>
    <p style="color:#666; font-size:14px; margin-bottom:25px;">คุณต้องการออกจากระบบ Deskspace ใช่หรือไม่</p>
    
    <div class="ds-row">
        <button type="button" class="ds-btn-ghost" onclick="dsAuthModal.switchView('profile')">ยกเลิก</button>
        <button type="button" class="ds-btn-primary" style="background:#dc2626; border-color:#dc2626;" onclick="dsAuthModal.confirmLogout()">ยืนยัน</button>
    </div>
</div>

            <div id="ds-msg-box" style="display:none; margin-top:15px; padding:10px; border-radius:8px; font-size:14px; text-align:center;"></div>
        </div>
    </div>
</div>


<script>
window._rafRedraw ??= null;

  (async function(){
    const API = <?php echo json_encode(EC_DRAW_API_URL); ?>;
    const AJAX_URL = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
    const META_CACHE_KEY = 'dpb_meta_cache_v1';
    const byId = id => document.getElementById(id);
    const FIXED_DRAW_LEN = 1050;
    const GAP = 0;
    const DESK_TOP_SPACE = 55;
    const DESK_BOTTOM_SPACE = 68; 
    const PAD = { left:0, right:0, top:32, bottom:-45 };
    const UI_INK = '#111827';
    const BRAND_LOGO_URL = 'https://www.deskspace.in.th/wp-content/uploads/2025/10/Logo_DeskSpace_Config-1.webp';
    const WM_TOP_COLOR_MAP = {
      'beech':'black',
      'maple':'black',
      'vintage oak':'white',
      'cherry':'white',
      'cherry capucino':'white',
      'bark walnut':'white',
      'oak':'black',
      'natural oak':'black',
      'modioak':'white',
      'white solid':'black',
      'grey':'black',
      'graphite':'white',
      'black solid':'white',
	  'whiteboard':'black',
      'radiata pine':'black',
      'rubber':'black'
    };
    const MIN_LEN_CM        = 100;
    const MIN_W_CM          = 45;
    const MIN_AW_CM         = 90;
    const RAW_MAX_DESK_H    = 4096;
    const RAW_MAX_OPT_H     = 6000;
    const GLOBAL_MAX_CANVAS = 12000;
    const TRASH_SVG = `<svg width="18" height="18" viewBox="0 0 520 520" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M402.06,53.33H339.77l-3.71-8.12C332.32,37,324.54,20,305.08,20H206.61c-19.35,0-27,16.86-30.68,25l-3.8,8.37H109.94a62.11,62.11,0,0,0-62,62,12,12,0,0,0,12,12H452.09a12,12,0,0,0,12-12A62.11,62.11,0,0,0,402.06,53.33Z" fill="#111827" fill-rule="evenodd"/>
  <defs>
    <mask id="bin-holes" maskUnits="userSpaceOnUse">
      <rect x="96.75" y="143.36" width="318.49" height="348" rx="24" ry="24" fill="#fff"/>
      <rect class="hole" x="175" y="235" width="36" height="210" rx="18" ry="18" fill="#000"/>
      <rect class="hole" x="309" y="235" width="36" height="210" rx="18" ry="18" fill="#000"/>
    </mask>
  </defs>
  <rect x="96.75" y="143.36" width="318.49" height="348" rx="24" ry="24" fill="#111827" mask="url(#bin-holes)"/>
</svg>
`;
    const BOTTOM_GAP_AFTER_INFO = 0;
    const INFO = {boxPadX:20, boxPadY:18, colGap:50, rowGap:6,
      radius:16,
      shadow:{ blur:24, color:'rgba(0,0,0,.08)'},
      bg:'#ffffff',
      ink:'#000000',
      topic:'#a37d13',
      headFont:'400 20px Anta, sans-serif',
      rowFont:'300 18px Prompt, sans-serif',
      headLH:24, rowLH:22
    };
    const CARD = { cardW:160, imgH:100, gap:8, radius:14, shadow:'rgba(0,0,0,0.08)' };
    const OPTCARD = {
      textH:44,
      nameFont:'400 13px Prompt, sans-serif',
      variantFont:'400 12px Prompt, sans-serif',
      nameYGap:12,
      variantYGap:30,
      badgePad:8,
      badgeR:11
    };
    const state = {
      meta:{colors:[],legs:[],options:[],models:[]},
      selectedOptions:{},
      optConfig:{},
      colorImgCache:{},
      optImgCache:{},
      prevR:{rect:{tl:50,tr:50,bl:50,br:50}, l:{tl:50,tr:50,step:90,arm:150,br:50,in:150}},
      uiExpanded:{},
      desktopCartOpen:false
    };
    const DPB_SOLID_KEYS = ['Radiata', 'Rubber'];
	
	// --- [ส่วนที่ 2] รับค่า PHP และสร้างปุ่ม Toggle ---
    // รับค่าจาก PHP
    var canShow3DButton = <?php echo $canShow3D_PHP ? 'true' : 'false'; ?>;
    
    // กำหนดค่าเริ่มต้นของโหมดมุมมอง
    if (typeof window.dpbViewMode === 'undefined') {
        window.dpbViewMode = 'top'; 
    }

    // สร้างปุ่มเมื่อโหลดหน้าเว็บ
    if (canShow3DButton && !document.getElementById('dpb-3d-toggle-btn')) {
        const btn = document.createElement('div');
        btn.id = 'dpb-3d-toggle-btn';
        // ไอคอน 3D Cube
        btn.innerHTML = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>`;
        
        // สไตล์ปุ่ม (มุมล่างซ้าย)
        Object.assign(btn.style, {
            position: 'fixed', bottom: '90px', left: '20px', 
            width: '50px', height: '50px',
            background: 'white', borderRadius: '50%', 
            boxShadow: '0 4px 10px rgba(0,0,0,0.3)',
            cursor: 'pointer', zIndex: '9999999', 
            display: 'flex', alignItems: 'center', justifyContent: 'center', 
            transition: 'all 0.2s', color: '#333'
        });
        
        // ฟังก์ชันเมื่อกดปุ่ม
        btn.onclick = function() {
            window.dpbViewMode = (window.dpbViewMode === 'top') ? '3d' : 'top';
            // เปลี่ยนสีปุ่มเมื่อ Active
            this.style.color = (window.dpbViewMode === '3d') ? '#007bff' : '#333';
            this.style.background = (window.dpbViewMode === '3d') ? '#e6f0ff' : 'white';
            // สั่งวาดใหม่
            if(typeof draw === 'function') draw();
        };
        document.body.appendChild(btn);
    }
    // ----------------------------------------------
	
const LEG_ASSETS = {
      white: {
        left:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/LegLeft-White3.5.png",
        center:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-Center-White.png",
        right: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-right-White3.5.png",
      },
      black: {
        left:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-left-Black3.5.png",
        center:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-Center-Black.png",
        right: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-right-Black3.5.png",
      }
    };

const SINGLE_MOTOR_ASSETS = {
  white: {
    right:  "https://www.deskspace.in.th/wp-content/uploads/2026/03/Leg-SingleMotor-Right-White.png",
    left:   "https://www.deskspace.in.th/wp-content/uploads/2026/03/Leg-SingleMotor-left-White.png",
    center: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-SingleMotor-center-White.webp"
  },
  black: {
    right:  "https://www.deskspace.in.th/wp-content/uploads/2026/03/Leg-SingleMotor-Right-Black.png",
    left:   "https://www.deskspace.in.th/wp-content/uploads/2026/03/Leg-SingleMotor-left-Black.png",
    center: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-SingleMotor-center-Black.webp"
  }
};

const WORKSPACE_ASSETS = {
  grey: {
    right:  "",
    left:   "",
    center: ""
  },
};
	
const MANUAL_DESK_ASSETS = {
  white: {
    right:     "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-right-White.png",
    left:      "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-left-White.png",
    center:    "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-Center-White.png",
    connector: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-Manual-Connect-White.png",
    crank:     "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-Manual-HandCrank.png" 
  },
  black: {
    right:     "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-right-Black.png",
    left:      "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-left-Black.png",
    center:    "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-CDManual-Center-Black.png",
    connector: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-Manual-Connect-Black.png",
    crank:     "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-Manual-HandCrank.png" 
  }
};

const SINGLE_LEG_ASSETS = {
  white: {
    leg:   "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-1Leg-White.png",
    cable: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-1Leg-cable.png"
  },
  black: {
    leg:   "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-1Leg-Black.png",
    cable: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-1Leg-cable.png"
  }
};

const LEG_ASSETS_L2 = {
  white: {
    left:   "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-left-White4.3.png",
    right:  "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-right-White4.3.png",
    leftL:  "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-L-left-White4.3.png",
    rightL: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-L-right-White4.3.png",
    center: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-Center-White.png",
  },
  black: {
    left:   "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-left-Black4.3.png",
    right:  "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-right-Black4.3.png",
    leftL:  "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-L-left-Black4.3.png",
    rightL: "https://www.deskspace.in.th/wp-content/uploads/2025/12/Leg-L-right-Black4.3.png",
    center: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Leg-Center-Black.png",
  }
};

const LEG_ASSETS_L3 = {
  white: {
    left: {
      centerLeft: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Center-Left-White.png",
      topCenter:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Center-White.png",
      bottomLeft: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Bottom-Left-White.png",
      topLeft:    "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Left-White.png",
      right:      "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Right-White.png",
    },
    right: {
      centerRight:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Center-Right-White.png",
      topCenter:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Center-White.png",
      bottomRight:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Bottom-Right-White.png",
      topRight:   "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Right-White.png",
      left:       "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Left-White.png",
    }
  },
  black: {
    left: {
      centerLeft: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Center-Left-Black.png",
      topCenter:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Center-Black.png",
      bottomLeft: "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Bottom-Left-Black.png",
      topLeft:    "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Left-Black.png",
      right:      "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Right-Black.png",
    },
    right: {
      centerRight:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Center-Right-Black.png",
      topCenter:  "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Center-Black.png",
      bottomRight:"https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Bottom-Right-Black.png",
      topRight:   "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Right-Black.png",
      left:       "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Left-Black.png",
    }
  }
};
	
// ============================================================================
// LEG ASSETS (ลิ้งค์รูปภาพขาโต๊ะ)3D
// ============================================================================

const LEG_3D_ASSETS = {
    // 1. Model: Custom (Dual Motor)
    'custom': {
        'square_white': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Left-White.webp',
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Right-White.webp'
                  },
        'square_black': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Left-Black.webp',
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Right-Black.webp'
        },
        'round_white': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp'
        },
        'round_black': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp'
        }
    },
    // 2. Model: Custom Manual
    'custom_manual': {
        'square_white': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp' 
        },
        'square_black': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp' 
        }
    },
    // 3. Model: Custom Single Motor
    'custom_single': {
        'square_white': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp' 
        },
        'square_black': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp' 
        }
    },
	
    // 4. Model: Single Leg (ขาเดียว)
    'single': {
        'white': { leg: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Left.webp' }, // ใช้ภาพแทนไปก่อน
        'black': { leg: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-Square-White_Right.webp' }
    },
	
   'l2': {
        'square_white': { 
            // กรณีหันทิศ L ไปทางขวา (Side Right)
            'right': {
                left:  'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Left-White.webp',  // ขาซ้ายปกติ
                right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-L-Leg-Square-Right-White.webp'   // ขาขวา (ทรง L)
            },
            // กรณีหันทิศ L ไปทางซ้าย (Side Left)
            'left': {
                left:  'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-L-Leg-Square-Left-White.webp',    // ขาซ้าย (ทรง L)
                right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Right-White.webp'  // ขาขวาปกติ
            }
        },
        'square_black': { 
            'right': {
                left:  'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Left-Black.webp',  // ขาซ้ายปกติ
                right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-L-Leg-Square-Right-Black.webp' 
            },
            'left': {
                left:  'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-L-Leg-Square-Left-Black.webp',    // ขาซ้าย (ทรง L)
                right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/CTD-Leg-Square-Right-Black.webp' 
            }
        }
    },

    'l3': {
        'square_white': { 
            // กรณีหันทิศ L ไปทางขวา (Side Right)
            'right': {
                left:   'LINK_L3_WHITE_RIGHTSIDE_LEFT',       // ขาซ้าย
                center: 'LINK_L3_WHITE_RIGHTSIDE_CENTER_TOP', // ขาขวาบน (Center)
                right:  'LINK_L3_WHITE_RIGHTSIDE_RIGHT_LOW'   // ขาขวาล่าง
            },
            // กรณีหันทิศ L ไปทางซ้าย (Side Left)
            'left': {
                left:   'LINK_L3_WHITE_LEFTSIDE_LEFT_LOW',    // ขาซ้ายล่าง
                center: 'LINK_L3_WHITE_LEFTSIDE_CENTER_TOP',  // ขาซ้ายบน (Center)
                right:  'LINK_L3_WHITE_LEFTSIDE_RIGHT'        // ขาขวา
            }
        },
        'square_black': { 
             'right': {
                left:   'LINK_L3_BLACK_RIGHTSIDE_LEFT',
                center: 'LINK_L3_BLACK_RIGHTSIDE_CENTER_TOP',
                right:  'LINK_L3_BLACK_RIGHTSIDE_RIGHT_LOW'
            },
            'left': {
                left:   'LINK_L3_BLACK_LEFTSIDE_LEFT_LOW',
                center: 'LINK_L3_BLACK_LEFTSIDE_CENTER_TOP',
                right:  'LINK_L3_BLACK_LEFTSIDE_RIGHT'
            }
        }
    },
	// 5. Model: Dual WorkSpace
    'custom_workspace': {
        'circle_grey': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Right.webp' 
        },
		'circle_black': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Right.webp' 
        },
		'circle_white': { 
            left: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Left.webp', 
            right: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Leg-circle-Grey_Right.webp' 
        },
    }
	
};


	
    let _isAddAnimating = false;
    function getVisibleCartTarget() {
      const footerBtn = document.getElementById('dpb-footer-cart-btn');
      if (footerBtn && footerBtn.offsetParent !== null) {
          const footer = footerBtn.closest('.dpb-sticky-footer');
          if (footer && getComputedStyle(footer).display !== 'none') {
              return footerBtn;
          }
      }
      const floatBtn = document.getElementById('dpb-cart-button');
      if (floatBtn && floatBtn.offsetParent !== null) {
          return floatBtn;
      }
      return null;
    }

    function flyImageToCartFrom(el){
      if(!el || _isAddAnimating) return Promise.resolve();
      const targetBtn = getVisibleCartTarget();
      if(!targetBtn) return Promise.resolve();
      const srcRect  = el.getBoundingClientRect();
      const cartRect = targetBtn.getBoundingClientRect();
      const ghost = el.cloneNode(true);
      ghost.style.position = 'fixed';
      ghost.style.left = srcRect.left + 'px';
      ghost.style.top = srcRect.top + 'px';
      ghost.style.width = srcRect.width + 'px';
      ghost.style.height = srcRect.height + 'px';
      ghost.style.borderRadius = '12px';
      ghost.style.zIndex = '10020';
      ghost.style.pointerEvents = 'none';
      ghost.style.transition = 'transform .55s ease, opacity .55s ease';
      ghost.style.willChange = 'transform, opacity';
      document.body.appendChild(ghost);
      const finalScale = 0.4;
      const toX = (cartRect.left + cartRect.width/2) - (srcRect.left + srcRect.width/2);
      const toY = (cartRect.top  + cartRect.height/2) - (srcRect.top  + srcRect.height/2);
      _isAddAnimating = true;
      return new Promise(resolve=>{
        ghost.getBoundingClientRect();
        requestAnimationFrame(()=>{
          ghost.style.transform = `translate(${toX}px, ${toY}px) scale(${finalScale})`;
          ghost.style.opacity = '0';
        });
        const done = ()=>{
          if(ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
          _isAddAnimating = false;
          resolve();
        };
        ghost.addEventListener('transitionend', done, { once:true });
        setTimeout(done, 900);
      });
    }
	 function flyBitmapToCart(imgSrc, rect){
      if(!imgSrc || !rect) return Promise.resolve();
      const targetBtn = getVisibleCartTarget();
      if(!targetBtn || _isAddAnimating) return Promise.resolve();
      const ghost = document.createElement('img');
      ghost.src = imgSrc;
      ghost.alt = '';
      ghost.style.position = 'fixed';
      ghost.style.left = rect.left + 'px';
      ghost.style.top = rect.top + 'px';
      ghost.style.width = rect.width + 'px';
      ghost.style.height = rect.height + 'px';
      ghost.style.borderRadius = '12px';
      ghost.style.zIndex = '10020';
      ghost.style.pointerEvents = 'none';
      ghost.style.objectFit = 'cover';
      ghost.style.transition = 'transform .55s ease, opacity .55s ease';
      ghost.style.willChange = 'transform, opacity';
      document.body.appendChild(ghost);
      const cartRect = targetBtn.getBoundingClientRect();
      const finalScale = 0.4;
      const toX = (cartRect.left + cartRect.width/2) - (rect.left + rect.width/2);
      const toY = (cartRect.top  + cartRect.height/2) - (rect.top  + rect.height/2);
      _isAddAnimating = true;
      return new Promise(resolve=>{
        const kick = ()=>{
          ghost.getBoundingClientRect();
          requestAnimationFrame(()=>{
            ghost.style.transform = `translate(${toX}px, ${toY}px) scale(${finalScale})`;
            ghost.style.opacity = '0';
          });
        };
        if(ghost.complete){ kick(); } else { ghost.onload = kick; ghost.onerror = kick; }
        const done = ()=>{
          if(ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
          _isAddAnimating = false;
          resolve();
        };
        ghost.addEventListener('transitionend', done, { once:true });
        setTimeout(done, 950);
      });
    }

    window.flyImageToCartFrom = flyImageToCartFrom;
    window.flyBitmapToCart = flyBitmapToCart;

    function buildVariantModalUI(modal, op){
      const back    = modal.querySelector('.dpb-modal__backdrop');
      const closeB  = modal.querySelector('.dpb-modal__close');
      const thumb   = modal.querySelector('.dpb-modal__thumb img');
      const body    = modal.querySelector('.dpb-modal__body');
      let headerDiv = modal.querySelector('.dpb-modal__header');
      let titleGroup = headerDiv.querySelector('.dpb-modal__title-group');
      if (!titleGroup) {
        const oldTitle = headerDiv.querySelector('.dpb-modal__title');
        if(oldTitle) oldTitle.remove();
        titleGroup = document.createElement('div');
        titleGroup.className = 'dpb-modal__title-group';
        headerDiv.appendChild(titleGroup);
      }
      const nameEn = op.name || op.key || '-';
      const nameTh = op.nameth || ''; 
      titleGroup.innerHTML = `
        <div class="dpb-modal__title-main">${nameEn}</div>
        ${nameTh ? `<div class="dpb-modal__title-sub">${nameTh}</div>` : ''}
      `;
      const headImg = (String(op.imageUrl||'').split(',').map(s=>s.trim()).filter(Boolean)[0]) || '';
      if (thumb){ thumb.src = headImg; thumb.alt = nameEn; }
      body.innerHTML = '';
      if (op.description) {
        const descEl = document.createElement('div');
        descEl.className = 'dpb-modal__desc';
        descEl.innerHTML = op.description.replace(/\n/g, '<br>'); 
        body.appendChild(descEl);
      }
      const wrap = document.createElement('div');
      wrap.style.display = 'grid';
      wrap.style.gap = '12px';
      const realVariants = Array.isArray(op.variants) ? op.variants : [];
const hasVariants  = realVariants.length > 0;
let variants = hasVariants ? realVariants : [{
    name: 'Standard',
    img: headImg,
    isDummy: true
}];
      const getVarImg = (v) => v.img || v.image || v.imageUrl || (Array.isArray(v.images)&&v.images[0]) || headImg || '';
      let selectedVariant = ''; 
      if (hasVariants){
        const varGrp = document.createElement('div');
        varGrp.className = 'dpb-form-group';
        varGrp.innerHTML = `<label class="dpb-form-label" style="font-weight:600; display:block; margin-bottom:8px;">ตัวเลือก</label>`;
        const grid = document.createElement('div');
        grid.className = 'dpb-variant-tiles';
        varGrp.appendChild(grid);
        variants.forEach((v, idx)=>{
          let label = String(v.name ?? v.label ?? '').trim();
          if(!label || label === '-') label = nameEn; 
          const img  = getVarImg(v);
          const tile = document.createElement('button');
          tile.type = 'button';
          tile.className = 'dpb-variant-tile';
          tile.setAttribute('role','radio');
          tile.setAttribute('aria-checked','false');
          tile.innerHTML = `
            <span class="dpb-variant-tile__chip">${img ? `<img src="${img}" alt="${label}">` : ''}</span>
            <span class="dpb-variant-tile__name">${label}</span>
          `;
          const selectTile = (el, variantObj)=>{
            [...grid.querySelectorAll('.dpb-variant-tile')].forEach(t => t.setAttribute('aria-checked','false'));
            el.setAttribute('aria-checked','true');
            selectedVariant = el.querySelector('.dpb-variant-tile__name')?.textContent?.trim() || '';
            if(variantObj && variantObj.isDummy) selectedVariant = '';
            const newImg = getVarImg(variantObj || {});
            if (thumb && newImg) thumb.src = newImg;
          };
          tile.addEventListener('click', ()=> selectTile(tile, v));
          grid.appendChild(tile);
          if (idx === 0) setTimeout(()=> selectTile(tile, v), 0);
        });
        wrap.appendChild(varGrp);
      }

      // =====================================================================
      // [ส่วนที่เพิ่มใหม่] Notification Banner สำหรับลิ้นชัก (Drawer)
      // =====================================================================
      const currentWidthInput = document.getElementById('dpb-mw');
      const currentTableWidth = currentWidthInput ? parseFloat(currentWidthInput.value) : 0;
      
      let isDrawerDisabled = false; // สร้างตัวแปรเก็บสถานะการปิดปุ่ม
      
      if (op.key && op.key.toLowerCase() === 'drawer' && currentTableWidth < 80) {
        isDrawerDisabled = true; // เปลี่ยนสถานะเป็น true เมื่อเข้าเงื่อนไข

        const drawerAlert = document.createElement('div');
        drawerAlert.className = 'dpb-drawer-alert';
        drawerAlert.style.display = 'flex';
        drawerAlert.style.alignItems = 'flex-start';
        drawerAlert.style.gap = '12px';
        drawerAlert.style.padding = '12px 16px';
        drawerAlert.style.backgroundColor = '#f9f3f3'; 
        drawerAlert.style.borderRadius = '8px';
        drawerAlert.style.color = '#c51515'; 
        drawerAlert.style.fontSize = '14px';
        drawerAlert.style.lineHeight = '1.5';
        drawerAlert.innerHTML = `
          <div style="flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; color: #c51515; margin-top: 2px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 100%; height: 100%;">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
          </div>
          <div>
            <strong style="display: block; font-weight: 600; margin-bottom: 2px;">ข้อแนะนำ</strong>
            Drawer (ลิ้นชัก) รองรับการติดตั้งกับโต๊ะที่มีความกว้าง 80 cm ขึ้นไปเท่านั้น
          </div>
        `;
        wrap.appendChild(drawerAlert);
      }
      // =====================================================================

      const qtyGrp = document.createElement('div');
      qtyGrp.className = 'dpb-form-group dpb-form-group--qty';
      qtyGrp.innerHTML = `
        <span class="dpb-form-label" style="font-weight:600;">จำนวน</span>
        <div class="dpb-qty">
          <button type="button" class="dpb-qty__btn" data-act="dec">−</button>
          <input id="dpb-qty-input" type="number" min="1" value="1" readonly />
          <button type="button" class="dpb-qty__btn" data-act="inc">+</button>
        </div>
      `;
      const qtyInput = qtyGrp.querySelector('#dpb-qty-input');
      qtyGrp.addEventListener('click', (ev)=>{
        const b = ev.target.closest('.dpb-qty__btn');
        if(!b) return;
        const act = b.dataset.act;
        let val = parseInt(qtyInput.value) || 1;
        if(act === 'inc') val++;
        else val = Math.max(1, val - 1);
        qtyInput.value = val;
      });
      wrap.appendChild(qtyGrp);
      body.appendChild(wrap);
      
      return {
        back, closeB,
        getVariant: ()=> selectedVariant,
        getQty: ()=> Math.max(1, +qtyInput.value||1),
        hasVariants,
        isDrawerDisabled // <--- เพิ่มการส่งค่าสถานะออกไปตรงนี้
      };
    }

    (function initMiniRemoveConfirm(){
      const modal   = document.getElementById('dpb-remove-confirm');
      if (!modal) return;
      const backdrop = modal.querySelector('#dpb-remove-confirm-backdrop');
      const btnNo    = modal.querySelector('.dpb-mini-confirm__no');
      const btnYes   = modal.querySelector('.dpb-mini-confirm__yes');
      const titleEl  = modal.querySelector('#dpb-remove-confirm-title');
      let onYesCb = null, onNoCb = null;
      window.showMiniRemoveConfirm = function({ title, onYes, onNo }){
        if (titleEl && title) titleEl.textContent = title;
        onYesCb = typeof onYes === 'function' ? onYes : null;
        onNoCb  = typeof onNo  === 'function' ? onNo  : null;
        modal.setAttribute('aria-hidden','false');
        modal.classList.add('is-open');
      };
      window.hideMiniRemoveConfirm = function(){
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden','true');
        onYesCb = null; onNoCb = null;
      };
      backdrop?.addEventListener('click', ()=>{ onNoCb?.(); hideMiniRemoveConfirm(); });
      btnNo?.addEventListener('click',    ()=>{ onNoCb?.(); hideMiniRemoveConfirm(); });
      btnYes?.addEventListener('click',   ()=>{ onYesCb?.(); });
    })();

    const cartBackdrop = byId('dpb-cart-backdrop');
    const cartPanel = byId('dpb-cart-panel');
    const cartBody = byId('dpb-cart-body');
    const cartEmpty = byId('dpb-cart-empty');
    const cartClear = byId('dpb-cart-clear');
    const cartCloseMobile = byId('dpb-cart-close-mobile');
    const cartCloseDesktop = byId('dpb-cart-close-desktop');
    const cartConfirm = byId('dpb-cart-confirm');
    const confirmDialog = byId('dpb-confirm');
    const confirmYes = confirmDialog.querySelector('[data-confirm="yes"]');
    const confirmNo = confirmDialog.querySelector('[data-confirm="no"]');
    const mainWrap = document.querySelector('.dpb-wrap');
    const supportsInert = 'inert' in HTMLElement.prototype;
    const supportsHistory = typeof window !== 'undefined' && !!(window.history && window.history.pushState);
	const PRELOAD_EL = (typeof document !== 'undefined') ? document.getElementById('preload') : null;

    function loadBrandLogo(onload){
      const key = BRAND_LOGO_URL;
      window.__desk_img_cache = window.__desk_img_cache || {};
      const cached = window.__desk_img_cache[key];
      if (cached && cached.complete) return cached;
      const im = new Image();
      im.crossOrigin = 'anonymous';
      if (typeof onload === 'function') im.onload = onload;
      im.src = key;
      window.__desk_img_cache[key] = im;
      return im;
    }

    let cartHistoryToken = null;
    let suppressPopstate = false;
    window.__dpb_legGapPrefs = {
      useDefaultsCustom: false,
      useDefaultsL2:      true,
      useDefaultsL3:      false
    };
    const cartButton = byId('dpb-cart-button');
    const cartCount = byId('dpb-cart-count');

    function getOptionMeta(key){
      return (state.meta.options || []).find(o=>o.key===key) || null;
    }

    (function(){
      if (typeof window.scheduleRedraw === 'function') {
        var __origScheduleRedraw = window.scheduleRedraw;
        window.scheduleRedraw = function(){
          if (typeof window.dpb_validateLegGaps === 'function') {
            var g = dpb_validateLegGaps();
            if (!g.ok) return;
          }
          return __origScheduleRedraw.apply(this, arguments);
        };
      }
    })();

    const LEG_DIMS_CM = {
      left:  { w: 50.5, h: 57.5 },
      center:{ w:110.0, h: 13.1 },
      right: { w: 54.5, h: 57.5 }
    };



    const LEG_IMG_CACHE = Object.create(null);

    function dpb_calcCustomOverlap(Lcm, gapL, gapR, allowCm = 4){
      const wL = LEG_DIMS_CM.left.w;
      const wR = LEG_DIMS_CM.right.w;
      const threshold = Lcm - (wL + wR) + allowCm;
      const over = (gapL + gapR) - threshold;
      const overInt = cmOverToInt(over);
      if (overInt > 0){
        const maxA = Math.max(5, Math.round(gapL - overInt));
        const maxB = Math.max(5, Math.round(gapR - overInt));
        return { ok:false, overCm:overInt, maxA, maxB };
      }
      return { ok:true, overCm:0, maxA:gapL, maxB:gapR };
    }

function getLegColorFromSelection(){
  const sel = document.getElementById('dpb-legs');
  const val = (sel && sel.value) ? String(sel.value) : '';
  const legs = (state && state.meta && Array.isArray(state.meta.legs)) ? state.meta.legs : [];
  const row  = legs.find(x => String(x.key) === val) || null;
  const haystack = [
    val,
    row && row.name,
    row && row.imageUrl
  ].filter(Boolean).join(' ').toLowerCase();
  if (/\bblack\b|ดำ|black\.|_black|\/black|black3|bk\b|blk\b/.test(haystack)) return 'black';
  if (/\bwhite\b|ขาว|white\.|_white|\/white|white3|wh\b|wht\b/.test(haystack)) return 'white';
  if (/\bgrey\b|เทา|grey\.|_grey|\/grey|grey3|gy\b|gry\b/.test(haystack)) return 'grey';
  if (val.toLowerCase().includes('black')) return 'black';
  if (val.toLowerCase().includes('white')) return 'white';
  if (val.toLowerCase().includes('grey')) return 'grey';
  return 'white';
}

function getLegAssetsBySelection(){
  const colorRaw = getLegColorFromSelection();
  const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  return LEG_ASSETS[color] || LEG_ASSETS.white;
}

window.__desk_img_cache = window.__desk_img_cache || {};

function loadLegImage(url, onload){
  if (!url) return null;
  const key = String(url);
  const cached = window.__desk_img_cache[key];
  if (cached && cached.complete) return cached;
  const im = new Image();
  im.crossOrigin = 'anonymous';
  if (typeof onload === 'function') im.onload = onload;
  im.src = key;
  window.__desk_img_cache[key] = im;
  return im;
}

function computeLegLayoutRectDesk(args){
  const { x, y, w, h, sc, Lcm, Wcm } = args;
  const gaps = (typeof window.dpb_getLegGaps==='function') ? window.dpb_getLegGaps() : {A:5,B:5};
  const leftOffsetCm  = Math.max(5, gaps.A);
  const rightOffsetCm = Math.max(5, gaps.B);
  const leftW   = LEG_DIMS_CM.left.w   * sc;
  const leftH   = LEG_DIMS_CM.left.h   * sc;
  const rightW  = LEG_DIMS_CM.right.w  * sc;
  const rightH  = LEG_DIMS_CM.right.h  * sc;
  const centerW = LEG_DIMS_CM.center.w * sc;
  const centerH = LEG_DIMS_CM.center.h * sc;
  const leftX  = x + (leftOffsetCm * sc);
  const leftY  = y + (h - leftH) / 2;
  const rightX = x + w - (rightOffsetCm * sc) - rightW;
  const rightY = y + (h - rightH) / 2;
  const centerX = x + (w - centerW) / 2;
  const centerY = y + (h - centerH) / 2;
  const cropLeftCm  = leftOffsetCm + LEG_DIMS_CM.left.w;
  const cropRightCm = Lcm - (rightOffsetCm + (LEG_DIMS_CM.right.w - 4.0));
  const cropLeftX  = x + cropLeftCm * sc;
  const cropRightX  = x + cropRightCm * sc;
  return {
    leftRect:  { x:leftX,  y:leftY,  w:leftW,    h:leftH    },
    rightRect: { x:rightX, y:rightY, w:rightW,  h:rightH  },
    centerRect: { x:centerX,y:centerY,w:centerW, h:centerH },
    crop:        { leftX:cropLeftX, rightX:cropRightX }
  };
}

function drawCustomDeskLegsLayer({ x, y, w, h, sc, topClip, alphaOverride }){
  if (state?.flags?.showLegs === false) return;
  const type = byId('dpb-type')?.value;
  if(type !== 'custom') return;
  const Lcm = +byId('dpb-ml').value || 0;
  const Wcm = +byId('dpb-mw').value || 0;
  const legAssetsSel = getLegAssetsBySelection();
  const imgL = loadLegImage(legAssetsSel.left,    scheduleRedraw);
  const imgC = loadLegImage(legAssetsSel.center, scheduleRedraw);
  const imgR = loadLegImage(legAssetsSel.right,  scheduleRedraw);
  if(!imgL || !imgC || !imgR) return;
  const layout = computeLegLayoutRectDesk({ x, y, w, h, sc, Lcm, Wcm });
  const elA = byId('dpb-gapA'), elB = byId('dpb-gapB');
  if (elA) setFieldError(elA, '');
  if (elB) setFieldError(elB, '');
  const valA = elA ? (+String(elA.value||'').trim() || 0) : 0;
  const valB = elB ? (+String(elB.value||'').trim() || 0) : 0;
  let okMin = true;
  if (elA && valA < 5){ setFieldError(elA, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); okMin=false; }
  if (elB && valB < 5){ setFieldError(elB, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); okMin=false; }
  if (!okMin) return;
  const { ok, overCm, maxA, maxB } = dpb_calcCustomOverlap(Lcm, valA, valB, 4);
  if (!ok){
    if (elA) setFieldError(elA, `กรุณาลดระยะห่างลงอีก ${overCm} cm (ห้ามเกิน ${maxA} cm)`);
    if (elB) setFieldError(elB, `กรุณาลดระยะห่างลงอีก ${overCm} cm (ห้ามเกิน ${maxB} cm)`);
    return;
  }
  ctx.save();
  if(topClip && topClip.enable){
    ctx.beginPath();
    if (ctx.roundRect){
      ctx.roundRect(topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii);
    } else {
      roundedPath(ctx, topClip.x, topClip.y, topClip.w, topClip.h,
        topClip.radii[0], topClip.radii[1], topClip.radii[2], topClip.radii[3]);
    }
    ctx.clip();
  }
  const colorRaw = getLegColorFromSelection();
  const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  let alpha = (legColor === 'black') ? 1.00 : 1.00;
  if (typeof alphaOverride === 'number') alpha = alphaOverride;
  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  try{ ctx.drawImage(imgR, layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h); }catch(e){}
  ctx.save();
  const cropW = Math.max(0, layout.crop.rightX - layout.crop.leftX);
  if(cropW > 0){
    ctx.beginPath();
    ctx.rect(layout.crop.leftX, y, cropW, h);
    ctx.clip();
    try{ ctx.drawImage(imgC, layout.centerRect.x, layout.centerRect.y, layout.centerRect.w, layout.centerRect.h); }catch(e){}
  }
  ctx.restore();
  try{ ctx.drawImage(imgL, layout.leftRect.x, layout.leftRect.y, layout.leftRect.w, layout.leftRect.h); }catch(e){}
  ctx.restore();
}

function cmOverToInt(over){
  if (over <= 0) return 0;
  return (over < 0.5) ? 0 : Math.ceil(over);
}

function l3_pxToCm(px){ return px / 10; }

function l3_cmToPx(cm, sc){ return cm * sc; }

const LEG_DIMS_L3_LEFT_CM = {
  centerLeft: { w: 13.1, h: 110.0 },
  topCenter:  { w: 110.0, h: 13.1 },
  bottomLeft: { w: 57.5, h: 51.0 },
  topLeft:    { w: 98.0,  h: 83.0 },
  right:      { w: 62.5,  h: 57.5 },
};

const LEG_DIMS_L3_RIGHT_CM = {
  centerRight:{ w: 13.1, h: 110.0 },
  topCenter:  { w: 110.0, h: 13.1 },
  bottomRight:{ w: 57.5, h: 51.0 },
  topRight:   { w: 98.0,  h: 83.0 },
  left:       { w: 62.5,  h: 57.5 },
};



const LEG_DIMS_L3_TOP_V3_CM = { w: 83.0, h: 98.0 };

LEG_ASSETS_L3.white.left.topLeft_v3    = "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Left-White-v3.png";
LEG_ASSETS_L3.white.right.topRight_v3  = "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Right-White-v3.png";
LEG_ASSETS_L3.black.left.topLeft_v3    = "https://www.deskspace.in.th/wp-content/uploads/2025/10/Left-3-Leg-Top-Left-Black-v3.png";
LEG_ASSETS_L3.black.right.topRight_v3  = "https://www.deskspace.in.th/wp-content/uploads/2025/10/Right-3-Leg-Top-Right-Black-v3.png";

function getL3AssetsAndDims(side){
  const color = (getLegColorFromSelection() || 'white').toLowerCase();
  const pal = LEG_ASSETS_L3[color] || LEG_ASSETS_L3.white;
  if (String(side).toLowerCase()==='left') {
    return { A: pal.left, D: LEG_DIMS_L3_LEFT_CM, side: 'left' };
  } else {
    return { A: pal.right, D: LEG_DIMS_L3_RIGHT_CM, side: 'right' };
  }
}

function computeLegLayoutL3Rects({ rect1, rect2, sc, Lcm, side }){
  const gaps = (typeof window.dpb_getLegGaps==='function') ? window.dpb_getLegGaps() : {A:5,B:5};
  if (side==='left'){
    const rightW = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.right.w, sc);
    const rightH = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.right.h, sc);
    const rightX = rect1.x + rect1.w - l3_cmToPx(Math.max(5,gaps.A), sc) - rightW;
    const rightY = rect1.y + (rect1.h - rightH)/2;
    const blW = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.bottomLeft.w, sc);
    const blH = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.bottomLeft.h, sc);
    const blX = rect2.x + (rect2.w - blW)/2;
    const blY = rect2.y + rect2.h - l3_cmToPx(Math.max(5,gaps.B), sc) - blH;
    const tlW = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.topLeft.w, sc);
    const tlH = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.topLeft.h, sc);
    const tlX = blX;
    const tlY = rightY;
    const tcW = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.topCenter.w, sc);
    const tcH = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.topCenter.h, sc);
    const tcX = rect1.x + (rect1.w - tcW)/2;
    const tcY = rect1.y + (rect1.h - tcH)/2;
    const clW = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.centerLeft.w, sc);
    const clH = l3_cmToPx(LEG_DIMS_L3_LEFT_CM.centerLeft.h, sc);
    const clX = rect2.x + (rect2.w - clW)/2;
    const clY = rect2.y + (rect2.h - clH)/2;
    const topLeftLeftInsetCm = (tlX - rect1.x) / sc;
    const cropLeftX  = rect1.x + l3_cmToPx(topLeftLeftInsetCm + 98.0, sc);
    const cropRightX = rect1.x + rect1.w - l3_cmToPx(Math.max(5,gaps.A) + 51.0, sc);
    const cropTopY   = rect1.y + l3_cmToPx(((tlY - rect1.y)/sc) + 83.0, sc);
    const cropBotY   = rect2.y + rect2.h - l3_cmToPx(56.0, sc);
    return {
      side:'left',
      rightRect:{ x:rightX, y:rightY, w:rightW, h:rightH },
      bottomLeft:{ x:blX, y:blY, w:blW, h:blH },
      topLeft:{ x:tlX, y:tlY, w:tlW, h:tlH },
      topCenter:{ x:tcX, y:tcY, w:tcW, h:tcH },
      centerLeft:{ x:clX, y:clY, w:clW, h:clH },
      cropTopCenterX:{ leftX: cropLeftX, rightX: cropRightX },
      cropCenterLeftY:{ topY: cropTopY, botY: cropBotY },
    };
  } else {
    const leftW = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.left.w, sc);
    const leftH = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.left.h, sc);
    const leftX = rect1.x + l3_cmToPx(Math.max(5,gaps.A), sc);
    const leftY = rect1.y + (rect1.h - leftH)/2;
    const brW = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.bottomRight.w, sc);
    const brH = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.bottomRight.h, sc);
    const brX = rect2.x + (rect2.w - brW)/2;
    const brY = rect2.y + rect2.h - l3_cmToPx(Math.max(5,gaps.B), sc) - brH;
    const trW = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.topRight.w, sc);
    const trH = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.topRight.h, sc);
    const trX = brX + (brW - trW);
    const trY = leftY;
    const tcW = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.topCenter.w, sc);
    const tcH = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.topCenter.h, sc);
    const tcX = rect1.x + (rect1.w - tcW)/2;
    const tcY = rect1.y + (rect1.h - tcH)/2;
    const crW = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.centerRight.w, sc);
    const crH = l3_cmToPx(LEG_DIMS_L3_RIGHT_CM.centerRight.h, sc);
    const crX = brX + (brW - crW)/2;
    const crY = rect2.y + (rect2.h - crH)/2;
    const rightInsetPx = (rect1.x + rect1.w) - (trX + trW);
    const cropTopCenterX = {
      leftX : rect1.x + l3_cmToPx(Math.max(5,gaps.A) + 51.0, sc),
      rightX: (rect1.x + rect1.w) - (rightInsetPx + l3_cmToPx(98.0, sc)),
    };
    const cropCenterRightY = {
      topY: rect1.y + ((trY - rect1.y) + l3_cmToPx(83.0, sc)),
      botY: rect2.y + rect2.h - l3_cmToPx(56.0, sc),
    };
    return {
      side:'right',
      leftRect:{ x:leftX, y:leftY, w:leftW, h:leftH },
      bottomRight:{ x:brX, y:brY, w:brW, h:brH },
      topRight:{ x:trX, y:trY, w:trW, h:trH },
      topCenter:{ x:tcX, y:tcY, w:tcW, h:tcH },
      centerRight:{ x:crX, y:crY, w:crW, h:crH },
      cropTopCenterX,
      cropCenterRightY,
    };
  }
}

if (typeof window.cmOverToInt !== 'function'){
  window.cmOverToInt = function cmOverToInt(over){
    if (over <= 0) return 0;
    return (over < 0.5) ? 0 : Math.ceil(over);
  };
}

function drawL3LegsLayer(args){
  if (state?.flags?.showLegs === false) return;
  if ((byId('dpb-type')?.value || '').trim() !== 'l3') return;
  const rect1 = args.rect1, rect2 = args.rect2, sc = args.sc;
  if (!rect1 || !rect2 || !sc) return;
  const sideSel = (args.side || byId('dpb-aside')?.value || 'right').toLowerCase();
  const Awcm    = +byId('dpb-aw')?.value || 0;
  const useV3   = Awcm >= 160;
  const pack = getL3AssetsAndDims(sideSel);
  const A = pack.A;
  const img = {};
  for (const k in A){ img[k] = loadLegImage(A[k], scheduleRedraw); }
  if (useV3){
    if (sideSel==='left'  && A.topLeft_v3)  img.topLeft_v3  = loadLegImage(A.topLeft_v3,  scheduleRedraw);
    if (sideSel==='right' && A.topRight_v3) img.topRight_v3 = loadLegImage(A.topRight_v3, scheduleRedraw);
  }
  if (!img.topCenter) return;
  const Lcm = +byId('dpb-ml')?.value || 0;
  const layout = computeLegLayoutL3Rects_SMART({ rect1, rect2, sc, Lcm, side:sideSel });
  if (!layout) return;
  const elGapA = byId('dpb-gapA'); if (elGapA) setFieldError(elGapA, '');
  const elGapB = byId('dpb-gapB'); if (elGapB) setFieldError(elGapB, '');
  const cm2px = (cm)=> cm*sc;
  const hasHOverlap = (a,b)=> Math.max(0, Math.min(a.x+a.w,b.x+b.w)-Math.max(a.x,b.x))>0;
  const vOverlapH   = (a,b)=> Math.max(0, Math.min(a.y+a.h,b.y+b.h)-Math.max(a.y,b.y));
  const hOverlapW   = (a,b)=> Math.max(0, Math.min(a.x+a.w,b.x+b.w)-Math.max(a.x,b.x));
  const valA = elGapA ? (+String(elGapA.value||'').trim() || 0) : 0;
  const valB = elGapB ? (+String(elGapB.value||'').trim() || 0) : 0;
  if (elGapB && valB < 5){ setFieldError(elGapB, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); return; }
  if (elGapA && valA < 5){ setFieldError(elGapA, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); return; }
  const ALLOW_BOTTOM_TOP_CM = (Awcm >= 135 && Awcm <= 144) ? 10 : 0;
  const ALLOW_BOTTOM_TOP_PX = cm2px(ALLOW_BOTTOM_TOP_CM);
  if (sideSel==='right'){
    if (layout.bottomRight && layout.topRight && elGapB){
      if (hasHOverlap(layout.bottomRight, layout.topRight)){
        const vpx = vOverlapH(layout.bottomRight, layout.topRight);
        if (vpx > ALLOW_BOTTOM_TOP_PX){
          const overInt = cmOverToInt((vpx - ALLOW_BOTTOM_TOP_PX)/sc);
          if (overInt > 0){
            const maxB = Math.max(5, Math.round(valB - overInt));
            setFieldError(elGapB, `กรุณาลดระยะห่างลงอีก ${overInt} cm (ห้ามเกิน ${maxB} cm)`);
            return;
          }
        }
      }
    }
  } else {
    if (layout.bottomLeft && layout.topLeft && elGapB){
      if (hasHOverlap(layout.bottomLeft, layout.topLeft)){
        const vpx = vOverlapH(layout.bottomLeft, layout.topLeft);
        if (vpx > ALLOW_BOTTOM_TOP_PX){
          const overInt = cmOverToInt((vpx - ALLOW_BOTTOM_TOP_PX)/sc);
          if (overInt > 0){
            const maxB = Math.max(5, Math.round(valB - overInt));
            setFieldError(elGapB, `กรุณาลดระยะห่างลงอีก ${overInt} cm (ห้ามเกิน ${maxB} cm)`);
            return;
          }
        }
      }
    }
  }
  const MAX_MAIN_TOP_OVERLAP_CM = 11.5;
  const maxMainTopOverlapPx     = cm2px(MAX_MAIN_TOP_OVERLAP_CM);
  if (sideSel === 'right'){
    const mainRect = layout.leftRect;
    const topRect  = layout.topRight;
    if (mainRect && topRect && elGapA){
      const ovpx = hOverlapW(mainRect, topRect);
      if (ovpx > maxMainTopOverlapPx){
        const excessInt = cmOverToInt((ovpx - maxMainTopOverlapPx)/sc);
        if (excessInt > 0){
          const maxA = Math.max(5, Math.round(valA - excessInt));
          setFieldError(elGapA, `กรุณาลดระยะห่างลงอีก ${excessInt} cm (ห้ามเกิน ${maxA} cm)`);
          return;
        }
      }
    }
  } else {
    const mainRect = layout.rightRect;
    const topRect  = layout.topLeft;
    if (mainRect && topRect && elGapA){
      const ovpx = hOverlapW(mainRect, topRect);
      if (ovpx > maxMainTopOverlapPx){
        const excessInt = cmOverToInt((ovpx - maxMainTopOverlapPx)/sc);
        if (excessInt > 0){
          const maxA = Math.max(5, Math.round(valA - excessInt));
          setFieldError(elGapA, `กรุณาลดระยะห่างลงอีก ${excessInt} cm (ห้ามเกิน ${maxA} cm)`);
          return;
        }
      }
    }
  }
  const alpha = (typeof args.alphaOverride === 'number') ? args.alphaOverride : 1.0;
  ctx.save();
  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  try{
    if (sideSel === 'left'){
      if (img.right)       ctx.drawImage(img.right,       layout.rightRect.x,        layout.rightRect.y,        layout.rightRect.w,        layout.rightRect.h);
      if (img.bottomLeft) ctx.drawImage(img.bottomLeft, layout.bottomLeft.x,       layout.bottomLeft.y,       layout.bottomLeft.w,       layout.bottomLeft.h);
      ctx.save();
      const lX = Math.max(layout.cropTopCenterX.leftX,  rect1.x);
      const rX = Math.min(layout.cropTopCenterX.rightX, rect1.x + rect1.w);
      const cw = Math.max(0, rX - lX);
      if (cw>0){
        ctx.beginPath(); ctx.rect(lX, rect1.y, cw, rect1.h); ctx.clip();
        ctx.drawImage(img.topCenter, layout.topCenter.x, layout.topCenter.y, layout.topCenter.w, layout.topCenter.h);
      }
      ctx.restore();
      if (img.centerLeft){
        ctx.save();
        const tY = Math.max(layout.cropCenterLeftY.topY, rect2.y);
        const bY = Math.min(layout.cropCenterLeftY.botY, rect2.y + rect2.h);
        const ch = Math.max(0, bY - tY);
        if (ch>0){
          ctx.beginPath(); ctx.rect(rect2.x, tY, rect2.w, ch); ctx.clip();
          ctx.drawImage(img.centerLeft, layout.centerLeft.x, layout.centerLeft.y, layout.centerLeft.w, layout.centerLeft.h);
        }
        ctx.restore();
      }
      if (useV3 && img.topLeft_v3){
        ctx.drawImage(img.topLeft_v3, layout.topLeft.x, layout.topLeft.y, layout.topLeft.w, layout.topLeft.h);
      }else if (img.topLeft){
        ctx.drawImage(img.topLeft,    layout.topLeft.x, layout.topLeft.y, layout.topLeft.w, layout.topLeft.h);
      }
    }else{
      if (img.left)        ctx.drawImage(img.left,        layout.leftRect.x,        layout.leftRect.y,        layout.leftRect.w,        layout.leftRect.h);
      if (img.bottomRight) ctx.drawImage(img.bottomRight,layout.bottomRight.x,    layout.bottomRight.y,     layout.bottomRight.w,     layout.bottomRight.h);
      ctx.save();
      const lX2 = Math.max(layout.cropTopCenterX.leftX,  rect1.x);
      const rX2 = Math.min(layout.cropTopCenterX.rightX, rect1.x + rect1.w);
      const cw2 = Math.max(0, rX2 - lX2);
      if (cw2>0){
        ctx.beginPath(); ctx.rect(lX2, rect1.y, cw2, rect1.h); ctx.clip();
        ctx.drawImage(img.topCenter, layout.topCenter.x, layout.topCenter.y, layout.topCenter.w, layout.topCenter.h);
      }
      ctx.restore();
      if (img.centerRight){
        ctx.save();
        const tY2 = Math.max(layout.cropCenterRightY.topY, rect2.y);
        const bY2 = Math.min(layout.cropCenterRightY.botY, rect2.y + rect2.h);
        const ch2 = Math.max(0, bY2 - tY2);
        if (ch2>0){
          ctx.beginPath(); ctx.rect(rect2.x, tY2, rect2.w, ch2); ctx.clip();
          ctx.drawImage(img.centerRight, layout.centerRight.x, layout.centerRight.y, layout.centerRight.w, layout.centerRight.h);
        }
        ctx.restore();
      }
      if (useV3 && img.topRight_v3){
        ctx.drawImage(img.topRight_v3, layout.topRight.x, layout.topRight.y, layout.topRight.w, layout.topRight.h);
      }else if (img.topRight){
        ctx.drawImage(img.topRight,    layout.topRight.x, layout.topRight.y, layout.topRight.w, layout.topRight.h);
      }
    }
  }catch(_){}
  ctx.restore();
  if (window.DPB_DEBUG){
    if (sideSel==='left'){
      l3_drawDebugRect(layout.rightRect,  '#4caf50','main');
      l3_drawDebugRect(layout.bottomLeft,  '#4caf50','bottom');
      l3_drawDebugRect(layout.topLeft,     '#4caf50','top');
      l3_drawDebugRect(layout.centerLeft,  '#ff9800','v-beam');
      l3_drawDebugRect(layout.topCenter,   '#ff9800','h-beam');
      if (layout.cropTopCenterX)  l3_drawDebugCropX(rect1, layout.cropTopCenterX.leftX,  layout.cropTopCenterX.rightX);
      if (layout.cropCenterLeftY) l3_drawDebugCropY(rect2, layout.cropCenterLeftY.topY,    layout.cropCenterLeftY.botY);
    }else{
      l3_drawDebugRect(layout.leftRect,    '#4caf50','main');
      l3_drawDebugRect(layout.bottomRight, '#4caf50','bottom');
      l3_drawDebugRect(layout.topRight,    '#4caf50','top');
      l3_drawDebugRect(layout.centerRight, '#ff9800','v-beam');
      l3_drawDebugRect(layout.topCenter,   '#ff9800','h-beam');
      if (layout.cropTopCenterX)    l3_drawDebugCropX(rect1, layout.cropTopCenterX.leftX,  layout.cropTopCenterX.rightX);
      if (layout.cropCenterRightY) l3_drawDebugCropY(rect2, layout.cropCenterRightY.topY, layout.cropCenterRightY.botY);
    }
  }
}

(function(){
  function readRectR(){
    return {
      tl: +byId('r_rect_tl')?.value || 0,
      tr: +byId('r_rect_tr')?.value || 0,
      bl: +byId('r_rect_bl')?.value || 0,
      br: +byId('r_rect_br')?.value || 0
    };
  }

  function writeRectR(r){
    if (byId('r_rect_tl')) byId('r_rect_tl').value = r.tl;
    if (byId('r_rect_tr')) byId('r_rect_tr').value = r.tr;
    if (byId('r_rect_bl')) byId('r_rect_bl').value = r.bl;
    if (byId('r_rect_br')) byId('r_rect_br').value = r.br;
  }

  function readLdeskR(){
    return {
      tl:   +byId('ld_r_tl')?.value   || 0,
      tr:   +byId('ld_r_tr')?.value   || 0,
      step: +byId('ld_r_step')?.value || 0,
      arm:  +byId('ld_r_armbl')?.value|| 0,
      br:   +byId('ld_r_br')?.value   || 0,
      in:   +byId('dpb-rInner')?.value|| 0
    };
  }

  function writeLdeskR(r){
    if (byId('ld_r_tl'))      byId('ld_r_tl').value = r.tl;
    if (byId('ld_r_tr'))      byId('ld_r_tr').value = r.tr;
    if (byId('ld_r_step'))    byId('ld_r_step').value = r.step;
    if (byId('ld_r_armbl'))   byId('ld_r_armbl').value = r.arm;
    if (byId('ld_r_br'))      byId('ld_r_br').value = r.br;
    if (byId('dpb-rInner'))   byId('dpb-rInner').value = r.in;
  }

  window.state = window.state || {};
  state.prevR = state.prevR || {
    rect: { tl:50, tr:50, bl:50, br:50 },
    l:    { tl:50, tr:50, step:90, arm:150, br:50, in:150 }
  };
  var edgeSel = byId('dpb-edge');
  if (!edgeSel) return;
  edgeSel.addEventListener('change', function(){
    var val = (edgeSel.value || '').toLowerCase();
    if (val === 'square'){
      try{ state.prevR.rect = readRectR(); }catch(_){}
      try{ state.prevR.l    = readLdeskR(); }catch(_){}
      if (byId('dpb-type')?.value === 'l2' || byId('dpb-type')?.value === 'l3'){
        writeLdeskR({ tl:0,tr:0,step:0,arm:0,br:0,in:0 });
      }else{
        writeRectR({ tl:0,tr:0,bl:0,br:0 });
      }
    }
    if (val === 'rounded'){
      if (byId('dpb-type')?.value === 'l2' || byId('dpb-type')?.value === 'l3'){
        writeLdeskR(state.prevR.l);
      }else{
        writeRectR(state.prevR.rect);
      }
    }
    if (typeof scheduleRedraw === 'function') scheduleRedraw();
  });
})();

function l3_drawDebugRect(r, color = '#ff2d2d', label = ''){
  if (!r) return;
  try{
    ctx.save();
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    ctx.setLineDash([6,4]);
    ctx.lineWidth = 2;
    ctx.strokeStyle = color;
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    if (label){
      ctx.font = '500 12px Prompt,sans-serif';
      ctx.fillStyle = color;
      ctx.fillText(label, r.x + 4, r.y - 6);
    }
    ctx.restore();
  }catch(_){}
}

function l3_drawDebugCropX(rect1, leftX, rightX, color = '#0099ff'){
  try{
    ctx.save();
    ctx.setLineDash([4,4]);
    ctx.strokeStyle = color; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(leftX, rect1.y); ctx.lineTo(leftX, rect1.y + rect1.h); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(rightX, rect1.y); ctx.lineTo(rightX, rect1.y + rect1.h); ctx.stroke();
    ctx.restore();
  }catch(_){}
}

function l3_drawDebugCropY(rect2, topY, botY, color = '#00aa66'){
  try{
    ctx.save();
    ctx.setLineDash([4,4]);
    ctx.strokeStyle = color; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(rect2.x, topY); ctx.lineTo(rect2.x + rect2.w, topY); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(rect2.x, botY); ctx.lineTo(rect2.x + rect2.w, botY); ctx.stroke();
    ctx.restore();
  }catch(_){}
}

function l3_rectsOverlap(a,b){
  return !(b.x >= a.x + a.w || b.x + b.w <= a.x || b.y >= a.y + a.h || b.y + b.h <= a.y);
}

function computeLegLayoutL3Rects_V2({ rect1, rect2, sc, Lcm, side }){
  const base = computeLegLayoutL3Rects({ rect1, rect2, sc, Lcm, side });
  const MIN_TOP_CM    = 5;
  const STEP_CM       = 0.5;
  const MAX_SHIFT_CM = 30;
  const SIDE_GUARD_CM= 5;
  const EPS_PX       = 0.25;
  const px = (cm)=> cm*sc;
  const hOverlap = (a,b)=> Math.max(0, Math.min(a.x+a.w,b.x+b.w) - Math.max(a.x,b.x));
  const isOverlap= (a,b)=> !(b.x >= a.x + a.w || b.x + b.w <= a.x || b.y >= a.y + a.h || b.y + b.h <= a.y);
  const clamp    = (v,lo,hi)=> Math.max(lo, Math.min(hi, v));
  const gaps = (typeof window.dpb_getLegGaps==='function') ? window.dpb_getLegGaps() : {A:5,B:5};
  const gapA = Math.max(5, gaps.A);
  const isLeft = String(side).toLowerCase()==='left';
  if (isLeft){
    let rightRect    = base.rightRect    && {...base.rightRect};
    let topLeft      = base.topLeft      && {...base.topLeft};
    let bottomLeft  = base.bottomLeft  && {...base.bottomLeft};
    let topCenter    = base.topCenter    && {...base.topCenter};
    let centerLeft  = base.centerLeft  && {...base.centerLeft};
    let cropX        = base.cropTopCenterX ? {...base.cropTopCenterX} : null;
    if (!rightRect||!topLeft||!bottomLeft||!topCenter||!centerLeft||!cropX) return base;
    (function(){
      if (!isOverlap(topLeft, bottomLeft)) return;
      const need    = (topLeft.y + topLeft.h) - bottomLeft.y + EPS_PX;
      const topGuard= rect1.y + px(MIN_TOP_CM);
      const canRaise= Math.max(0, topLeft.y - topGuard);
      const raise   = Math.min(need, canRaise);
      if (raise > 0){
        topLeft.y   -= raise;
        topCenter.y -= raise;
        rightRect.y -= raise;
      }
      if (isOverlap(topLeft, bottomLeft)){
        const extra = Math.min((topLeft.y + topLeft.h) - bottomLeft.y + EPS_PX,
                               Math.max(0, topLeft.y - topGuard));
        if (extra > 0){
          topLeft.y   -= extra;
          topCenter.y -= extra;
          rightRect.y -= extra;
        }
      }
    })();
    (function(){
      if (!isOverlap(rightRect, topLeft)) return;
      const step = px(STEP_CM), max = px(MAX_SHIFT_CM);
      let moved = 0;
      const leftGuard = rect1.x + px(SIDE_GUARD_CM);
      function canMoveLeft(dx){
        return (topLeft.x+dx)    >= leftGuard &&
              (bottomLeft.x+dx)>= leftGuard &&
              (centerLeft.x+dx)>= leftGuard &&
              (topCenter.x+dx) >= leftGuard;
      }
      while (moved <= max && isOverlap(rightRect, topLeft)){
        const dx = -Math.min(step, max - moved);
        if (!canMoveLeft(dx)) break;
        topLeft.x    += dx;
        bottomLeft.x += dx;
        centerLeft.x += dx;
        topCenter.x  += dx;
        moved += Math.abs(dx);
      }
      if (isOverlap(rightRect, topLeft)){
        const need = hOverlap(rightRect, topLeft) + EPS_PX;
        const dx   = -Math.min(need, (topLeft.x - leftGuard));
        if (dx < 0){
          topLeft.x    += dx;
          bottomLeft.x += dx;
          centerLeft.x += dx;
          topCenter.x  += dx;
        }
      }
      const topLeftInsetCm = (topLeft.x - rect1.x) / sc;
      cropX.leftX  = rect1.x + l3_cmToPx(topLeftInsetCm + 98.0, sc);
      cropX.rightX = rect1.x + rect1.w - l3_cmToPx(gapA + 51.0, sc);
    })();
    return {
      side: 'left',
      rightRect,
      bottomLeft,
      topLeft,
      topCenter,
      centerLeft,
      cropTopCenterX:  cropX,
      cropCenterLeftY: base.cropCenterLeftY
    };
  }
  let leftRect      = base.leftRect      && {...base.leftRect};
  let topRight      = base.topRight      && {...base.topRight};
  let bottomRight  = base.bottomRight  && {...base.bottomRight};
  let topCenter    = base.topCenter    && {...base.topCenter};
  let centerRight  = base.centerRight  && {...base.centerRight};
  let cropX        = base.cropTopCenterX    ? {...base.cropTopCenterX}    : null;
  if (!leftRect||!topRight||!bottomRight||!topCenter||!centerRight||!cropX) return base;
  (function(){
    if (!isOverlap(topRight, bottomRight)) return;
    const need     = (topRight.y + topRight.h) - bottomRight.y + EPS_PX;
    const topGuard = rect1.y + px(MIN_TOP_CM);
    const canRaise = Math.max(0, topRight.y - topGuard);
    const raise    = Math.min(need, canRaise);
    if (raise > 0){
      topRight.y   -= raise;
      topCenter.y  -= raise;
      leftRect.y   -= raise;
    }
    if (isOverlap(topRight, bottomRight)){
      const extra = Math.min((topRight.y + topRight.h) - bottomRight.y + EPS_PX,
                             Math.max(0, topRight.y - topGuard));
      if (extra > 0){
        topRight.y   -= extra;
        topCenter.y  -= extra;
        leftRect.y   -= extra;
      }
    }
  })();
  (function(){
    if (!isOverlap(leftRect, topRight)) return;
    const step = px(STEP_CM), max = px(MAX_SHIFT_CM);
    let moved = 0;
    const rightGuard = rect1.x + rect1.w - px(SIDE_GUARD_CM);
    function canMoveRight(dx){
      return (topRight.x+topRight.w+dx)    <= rightGuard &&
            (bottomRight.x+bottomRight.w+dx)<= rightGuard &&
            (centerRight.x+centerRight.w+dx)<= rightGuard &&
            (topCenter.x+topCenter.w+dx) <= rightGuard;
    }
    while (moved <= max && isOverlap(leftRect, topRight)){
      const dx = Math.min(step, max - moved);
      if (!canMoveRight(dx)) break;
      topRight.x    += dx;
      bottomRight.x += dx;
      centerRight.x += dx;
      topCenter.x   += dx;
      moved += dx;
    }
    if (isOverlap(leftRect, topRight)){
      const need = hOverlap(leftRect, topRight) + EPS_PX;
      const dx   = Math.min(need, rightGuard - (topRight.x + topRight.w));
      if (dx > 0){
        topRight.x    += dx;
        bottomRight.x += dx;
        centerRight.x += dx;
        topCenter.x   += dx;
      }
    }
    const rightInsetPx = (rect1.x + rect1.w) - (topRight.x + topRight.w);
    cropX.leftX  = rect1.x + l3_cmToPx(gapA + 51.0, sc);
    cropX.rightX = (rect1.x + rect1.w) - (rightInsetPx + l3_cmToPx(98.0, sc));
  })();
  return {
    side: 'right',
    leftRect,
    bottomRight,
    topRight,
    topCenter,
    centerRight,
    cropTopCenterX:  cropX,
    cropCenterRightY: base.cropCenterRightY
  };
}

function computeLegLayoutL3Rects_V3({ rect1, rect2, sc, Lcm, side }){
  const v1 = computeLegLayoutL3Rects({ rect1, rect2, sc, Lcm, side });
  const MIN_TOP_CM    = 5;
  const STEP_CM       = 0.5;
  const MAX_SHIFT_CM = 30;
  const SIDE_GUARD_CM= 5;
  const H_ALLOW_CM    = 11.5;
  const EPS_PX       = 0.25;
  const px = (cm)=> cm*sc;
  const isLeft = (String(side).toLowerCase()==='left');
  const gaps = (typeof window.dpb_getLegGaps==='function') ? window.dpb_getLegGaps() : {A:5,B:5};
  const gapA = Math.max(5, gaps.A);
  const hOverlapW = (a,b)=> Math.max(0, Math.min(a.x+a.w,b.x+b.w) - Math.max(a.x,b.x));
  const isOverlap = (a,b)=> !(b.x >= a.x + a.w || b.x + b.w <= a.x || b.y >= a.y + a.h || b.y + b.h <= a.y);
  if (isLeft){
    let rightRect    = v1.rightRect    && {...v1.rightRect};
    let bottomLeft  = v1.bottomLeft  && {...v1.bottomLeft};
    let topLeft      = v1.topLeft      && {...v1.topLeft};
    let topCenter    = v1.topCenter    && {...v1.topCenter};
    let centerLeft  = v1.centerLeft  && {...v1.centerLeft};
    let cropX        = v1.cropTopCenterX ? {...v1.cropTopCenterX} : null;
    let cropY        = v1.cropCenterLeftY? {...v1.cropCenterLeftY}: null;
    if (!rightRect||!bottomLeft||!topLeft||!topCenter||!centerLeft||!cropX||!cropY) return v1;
    const newW = px(LEG_DIMS_L3_TOP_V3_CM.w), newH = px(LEG_DIMS_L3_TOP_V3_CM.h);
    topLeft = { x: topLeft.x, y: rightRect.y, w: newW, h: newH };
    (function recalcCropX(){
      const insetCm = (topLeft.x - rect1.x) / sc;
      cropX.leftX  = rect1.x + l3_cmToPx(insetCm + 83.0, sc);
      cropX.rightX = rect1.x + rect1.w - l3_cmToPx(gapA + 51.0, sc);
    })();
    (function(){
      const vertOverlap = isOverlap(topLeft, bottomLeft);
      if (!vertOverlap) return;
      const need     = (topLeft.y + topLeft.h) - bottomLeft.y + EPS_PX;
      const topGuard = rect1.y + px(MIN_TOP_CM);
      const canRaise = Math.max(0, topLeft.y - topGuard);
      const raise    = Math.min(need, canRaise);
      if (raise > 0){ topLeft.y -= raise; topCenter.y -= raise; rightRect.y -= raise; }
      if ((topLeft.y + topLeft.h) > bottomLeft.y){
        const extra = Math.min((topLeft.y + topLeft.h) - bottomLeft.y + EPS_PX,
                               Math.max(0, topLeft.y - topGuard));
        if (extra > 0){ topLeft.y -= extra; topCenter.y -= extra; rightRect.y -= extra; }
      }
    })();

(function(){
      const allowPx = px(H_ALLOW_CM);
      let hov = hOverlapW(rightRect, topLeft);
      if (hov <= allowPx) return;
      const step = px(STEP_CM), max = px(MAX_SHIFT_CM);
      let moved = 0;
      const leftGuard = rect1.x + px(SIDE_GUARD_CM);
      const canMoveLeft = (dx)=>
        (topLeft.x+dx)    >= leftGuard &&
        (bottomLeft.x+dx)>= leftGuard &&
        (centerLeft.x+dx)>= leftGuard &&
        (topCenter.x+dx) >= leftGuard;
      while (moved <= max && hov > allowPx){
        const dx = -Math.min(step, max - moved);
        if (!canMoveLeft(dx)) break;
        topLeft.x    += dx;
        bottomLeft.x += dx;
        centerLeft.x += dx;
        topCenter.x  += dx;
        moved += Math.abs(dx);
        hov = hOverlapW(rightRect, topLeft);
      }
      if (hov > allowPx){
        const room = topLeft.x - leftGuard;
        const dx   = -Math.min(hov - allowPx, room);
        if (dx < 0){
          topLeft.x    += dx;
          bottomLeft.x += dx;
          centerLeft.x += dx;
          topCenter.x  += dx;
          hov = hOverlapW(rightRect, topLeft);
        }
      }
      const insetCm = (topLeft.x - rect1.x) / sc;
      cropX.leftX  = rect1.x + l3_cmToPx(insetCm + 83.0, sc);
      cropX.rightX = rect1.x + rect1.w - l3_cmToPx(gapA + 51.0, sc);
    })();
    return { side:'left', rightRect, bottomLeft, topLeft, topCenter, centerLeft,
            cropTopCenterX:  cropX, cropCenterLeftY:  cropY };
  }

  let leftRect      = v1.leftRect      && {...v1.leftRect};
  let bottomRight  = v1.bottomRight  && {...v1.bottomRight};
  let topRight      = v1.topRight      && {...v1.topRight};
  let topCenter    = v1.topCenter    && {...v1.topCenter};
  let centerRight  = v1.centerRight  && {...v1.centerRight};
  let cropX        = v1.cropTopCenterX    ? {...v1.cropTopCenterX}    : null;
  let cropY        = v1.cropCenterRightY ? {...v1.cropCenterRightY} : null;
  if (!leftRect||!bottomRight||!topRight||!topCenter||!centerRight||!cropX||!cropY) return v1;
  const newW = px(LEG_DIMS_L3_TOP_V3_CM.w), newH = px(LEG_DIMS_L3_TOP_V3_CM.h);
  const rEdge = topRight.x + topRight.w;
  topRight = { x: rEdge - newW, y: leftRect.y, w: newW, h: newH };

  (function recalcCropX(){
    const rightInsetPx = (rect1.x + rect1.w) - (topRight.x + topRight.w);
    cropX.leftX  = rect1.x + l3_cmToPx(gapA + 51.0, sc);
    cropX.rightX = (rect1.x + rect1.w) - (rightInsetPx + l3_cmToPx(83.0, sc));
  })();

  (function(){
    const vertOverlap = isOverlap(topRight, bottomRight);
    if (!vertOverlap) return;
    const need     = (topRight.y + topRight.h) - bottomRight.y + EPS_PX;
    const topGuard = rect1.y + px(MIN_TOP_CM);
    const canRaise = Math.max(0, topRight.y - topGuard);
    const raise    = Math.min(need, canRaise);
    if (raise > 0){ topRight.y -= raise; topCenter.y -= raise; leftRect.y -= raise; }
    if ((topRight.y + topRight.h) > bottomRight.y){
      const extra = Math.min((topRight.y + topRight.h) - bottomRight.y + EPS_PX,
                             Math.max(0, topRight.y - topGuard));
      if (extra > 0){ topRight.y -= extra; topCenter.y -= extra; leftRect.y -= extra; }
    }
  })();

  (function(){
    const allowPx = px(H_ALLOW_CM);
    let hov = hOverlapW(leftRect, topRight);
    if (hov <= allowPx) return;
    const step = px(STEP_CM), max = px(MAX_SHIFT_CM);
    let moved = 0;
    const rightGuard = rect1.x + rect1.w - px(SIDE_GUARD_CM);
    const canMoveRight = (dx)=>
      (topRight.x+topRight.w+dx)    <= rightGuard &&
      (bottomRight.x+bottomRight.w+dx)<= rightGuard &&
      (centerRight.x+centerRight.w+dx)<= rightGuard &&
      (topCenter.x+topCenter.w+dx)    <= rightGuard;
    while (moved <= max && hov > allowPx){
      const dx = Math.min(step, max - moved);
      if (!canMoveRight(dx)) break;
      topRight.x    += dx;
      bottomRight.x += dx;
      centerRight.x += dx;
      topCenter.x   += dx;
      moved += dx;
      hov = hOverlapW(leftRect, topRight);
    }
    if (hov > allowPx){
      const room = rightGuard - (topRight.x + topRight.w);
      const dx   = Math.min(hov - allowPx, room);
      if (dx > 0){
        topRight.x    += dx;
        bottomRight.x += dx;
        centerRight.x += dx;
        topCenter.x   += dx;
        hov = hOverlapW(leftRect, topRight);
      }
    }
    const rightInsetPx = (rect1.x + rect1.w) - (topRight.x + topRight.w);
    cropX.leftX  = rect1.x + l3_cmToPx(gapA + 51.0, sc);
    cropX.rightX = (rect1.x + rect1.w) - (rightInsetPx + l3_cmToPx(83.0, sc));
  })();

  return { side:'right', leftRect, bottomRight, topRight, topCenter, centerRight,
            cropTopCenterX:  cropX, cropCenterRightY: cropY };
}

function l3_layoutHasConflict(layout, rect1, rect2, side){
  let conflict = false;
  if (side === 'left'){
    const overlapLegs = (layout.topLeft && layout.bottomLeft)
      ? l3_rectsOverlap(layout.topLeft, layout.bottomLeft)
      : false;
    let cropH = Infinity;
    if (layout.cropCenterLeftY){
      const tY = Math.max(layout.cropCenterLeftY.topY, rect2.y);
      const bY = Math.min(layout.cropCenterLeftY.botY, rect2.y + rect2.h);
      cropH = Math.max(0, bY - tY);
      if (cropH < 1) conflict = true;
    }
    if (overlapLegs) conflict = true;
    if (window.DPB_DEBUG){
      console.groupCollapsed('%c[L3 Debug] Conflict check — side:left', 'color:#09f');
      console.log('topLeft:', layout.topLeft);
      console.log('bottomLeft:', layout.bottomLeft);
      console.log('overlapLegs:', overlapLegs);
      console.log('cropCenterLeftY:', layout.cropCenterLeftY, 'cropH(px):', cropH);
      console.groupEnd();
    }
  } else {
    const overlapLegs = (layout.topRight && layout.bottomRight)
      ? l3_rectsOverlap(layout.topRight, layout.bottomRight)
      : false;
    let cropH = Infinity;
    if (layout.cropCenterRightY){
      const tY = Math.max(layout.cropCenterRightY.topY, rect2.y);
      const bY = Math.min(layout.cropCenterRightY.botY, rect2.y + rect2.h);
      cropH = Math.max(0, bY - tY);
      if (cropH < 1) conflict = true;
    }
    if (overlapLegs) conflict = true;
    if (window.DPB_DEBUG){
      console.groupCollapsed('%c[L3 Debug] Conflict check — side:right', 'color:#09f');
      console.log('topRight:', layout.topRight);
      console.log('bottomRight:', layout.bottomRight);
      console.log('overlapLegs:', overlapLegs);
      console.log('cropCenterRightY:', layout.cropCenterRightY, 'cropH(px):', cropH);
      console.groupEnd();
    }
  }
  return conflict;
}

function computeLegLayoutL3Rects_SMART({ rect1, rect2, sc, Lcm, side }){
  const v1 = computeLegLayoutL3Rects({ rect1, rect2, sc, Lcm, side });
  const Awcm = +byId('dpb-aw')?.value || 0;
  if (Awcm >= 160){
    const v3 = computeLegLayoutL3Rects_V3({ rect1, rect2, sc, Lcm, side });
    if (window.DPB_DEBUG){
      console.group('%c[L3 Debug] Use Layout V3 (Awcm≥160)', 'color:#a0f;font-weight:bold');
      console.log('rect1:', rect1, 'rect2:', rect2, 'side:', side, 'Awcm:', Awcm);
      console.log('Layout V3:', v3);
      console.groupEnd();
    }
    return v3;
  }
  const hasConflict = l3_layoutHasConflict(v1, rect1, rect2, side);
  if (hasConflict){
    const v2 = computeLegLayoutL3Rects_V2({ rect1, rect2, sc, Lcm, side });
    if (window.DPB_DEBUG){
      console.group('%c[L3 Debug] Switch to Layout V2 (anti-overlap)', 'color:#f33;font-weight:bold');
      console.log('Layout V1:', v1);
      console.log('Layout V2:', v2);
      console.groupEnd();
    }
    return v2;
  }
  if (window.DPB_DEBUG){
    console.groupCollapsed('%c[L3 Debug] Keep Layout V1', 'color:#3a3');
    console.log('Layout V1:', v1);
    console.groupEnd();
  }
  return v1;
}

const LEG_DIMS_L2_CM = {
  left:   { w: 50.5, h: 57.5 },
  right:  { w: 54.5, h: 57.5 },
  leftL:  { w: 50.5, h: 90.0 },
  rightL: { w: 54.5, h: 90.0 },
  center: { w:110.0, h: 13.1 },
};

function getL2Assets(side){
  const colorRaw = getLegColorFromSelection();
  const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  const a = LEG_ASSETS_L2[color] || LEG_ASSETS_L2.white;
  if(side === 'right'){
    return { left:a.left, right:a.rightL, center:a.center, leftL:a.leftL, rightL:a.rightL, rightNormal:a.right };
  }else{
    return { left:a.leftL, right:a.right, center:a.center, leftL:a.leftL, rightL:a.rightL, leftNormal:a.left };
  }
}

function clipRoundedRect(ctx, rect, r){
  if (ctx.roundRect){
    ctx.beginPath();
    ctx.roundRect(rect.x, rect.y, rect.w, rect.h, [r.tl,r.tr,r.br,r.bl]);
    ctx.clip();
  }else{
    roundedPath(ctx, rect.x, rect.y, rect.w, rect.h, r.tl,r.tr,r.br,r.bl);
    ctx.clip();
  }
}

function computeLegLayoutL2Rect1({ x, y, w, h, sc, Lcm, side, dims }) {
  const gaps = (typeof window.dpb_getLegGaps==='function') ? window.dpb_getLegGaps() : {A:5,B:5};
  let leftOffsetCm, rightOffsetCm;
  if (side==='right'){ leftOffsetCm=Math.max(5,gaps.A); rightOffsetCm=Math.max(5,gaps.B); }
  else                { leftOffsetCm=Math.max(5,gaps.A); rightOffsetCm=Math.max(5,gaps.B); }
  const leftW   = dims.left.w   * sc, leftH   = dims.left.h   * sc;
  const rightW  = dims.right.w  * sc, rightH  = dims.right.h  * sc;
  const leftLW  = dims.leftL.w  * sc, leftLH  = dims.leftL.h  * sc;
  const rightLW = dims.rightL.w * sc, rightLH = dims.rightL.h * sc;
  const centW   = dims.center.w * sc, centH   = dims.center.h * sc;
  const leftY = y + (h - leftH)/2;
  const rightY= y + (h - rightH)/2;
  const centerX = x + (w - centW)/2;
  const centerY = y + (h - centH)/2;
  if (side === 'right') {
    const leftX   = x + leftOffsetCm * sc;
    const rightLX = x + w - rightOffsetCm * sc - rightLW;
    const rightLY = leftY;
    const cropLeftX  = x + (leftOffsetCm + dims.left.w) * sc;
    const cropRightX = x + (Lcm - (rightOffsetCm + dims.rightL.w - 4.0)) * sc;
    return {
      leftRect:  { x:leftX,  y:leftY,  w:leftW,  h:leftH  },
      rightRect: { x:rightLX,y:rightLY,w:rightLW,h:rightLH },
      centerRect: { x:centerX,y:centerY,w:centW,  h:centH  },
      crop:        { leftX: cropLeftX, rightX: cropRightX }
    };
  } else {
    const leftLX = x + leftOffsetCm * sc;
    const leftLY = rightY;
    const rightX = x + w - rightOffsetCm * sc - rightW;
    const rightY2= rightY;
    const cropLeftX  = x + (leftOffsetCm + dims.leftL.w) * sc;
    const cropRightX = x + (Lcm - (rightOffsetCm + dims.right.w - 4.0)) * sc;
    return {
      leftRect:  { x:leftLX,y:leftLY,w:leftLW,h:leftLH },
      rightRect: { x:rightX,y:rightY2,w:rightW,h:rightH },
      centerRect: { x:centerX,y:centerY,w:centW,h:centH },
      crop:        { leftX: cropLeftX, rightX: cropRightX }
    };
  }
}

function l2_legOverflowsRect2(layout, rect2, side){
  if (!rect2 || !layout) return false;
  var L = (String(side).toLowerCase()==='right') ? layout.rightRect : layout.leftRect;
  if (!L) return false;
  var topOk    = (L.y >= rect2.y);
  var bottomOk = (L.y + L.h <= rect2.y + rect2.h);
  return !(topOk && bottomOk);
}

function l2_overflowInfo(layout, rect2, side){
  if (!rect2 || !layout) return {overflow:false, top:0, bottom:0};
  var L = (String(side).toLowerCase()==='right') ? layout.rightRect : layout.leftRect;
  if (!L) return {overflow:false, top:0, bottom:0};
  var topOver = Math.max(0, (rect2.y - L.y));
  var botOver = Math.max(0, (L.y + L.h) - (rect2.y + rect2.h));
  return {overflow: (topOver>0 || botOver>0), top:topOver, bottom:botOver};
}

function l2_clamp(val, min, max){ return Math.max(min, Math.min(max, val)); }

function computeLegLayoutL2_V2(args){
  var rect1=args.rect1, rect2=args.rect2, sc=args.sc, Lcm=args.Lcm;
  var side=(args.side||'right').toLowerCase();
  var dims=args.dims;
  var leftOff = args.offsets.leftCm, rightOff = args.offsets.rightCm;
  var leftW   = dims.left.w   * sc, leftH   = dims.left.h   * sc;
  var rightW  = dims.right.w  * sc, rightH  = dims.right.h  * sc;
  var leftLW  = dims.leftL.w  * sc, leftLH  = dims.leftL.h  * sc;
  var rightLW = dims.rightL.w * sc, rightLH = dims.rightL.h * sc;
  var centW   = dims.center.w * sc, centH   = dims.center.h * sc;
  var cropLeftX, cropRightX;
  if (side==='right'){
    var rX = rect2.x + (rect2.w - rightLW)/2;
    var rY = rect2.y + (rect2.h - rightLH)/2;
    rY = l2_clamp(rY, rect2.y, rect2.y + rect2.h - rightLH);
    var lX = rect1.x + (leftOff * sc);
    var lY = rY;
    var cX = lX + (leftW - centW)/2;
    var cY = lY + (leftH/2) - (centH/2);
    cropLeftX  = rect1.x + ((leftOff + dims.left.w) * sc);
    cropRightX = rect1.x + (Lcm * sc) - ((rightOff + dims.rightL.w - 4.0) * sc);
    return {
      leftRect:{x:lX,y:lY,w:leftW,h:leftH},
      rightRect:{x:rX,y:rY,w:rightLW,h:rightLH},
      centerRect:{x:cX,y:cY,w:centW,h:centH},
      crop:{ leftX: cropLeftX, rightX: cropRightX },
      _mode:'V2-right'
    };
  }
  var lLX = rect2.x + (rect2.w - leftLW)/2;
  var lLY = rect2.y + (rect2.h - leftLH)/2;
  lLY = l2_clamp(lLY, rect2.y, rect2.y + rect2.h - leftLH);
  var rX2 = rect1.x + rect1.w - (rightOff * sc) - rightW;
  var rY2 = lLY;
  var cX2 = rX2 + (rightW - centW)/2;
  var cY2 = rY2 + (rightH/2) - (centH/2);
  cropLeftX  = rect1.x + ((leftOff + dims.leftL.w) * sc);
  cropRightX = rect1.x + (Lcm * sc) - ((rightOff + dims.right.w - 4.0) * sc);
  return {
    leftRect:{x:lLX,y:lLY,w:leftLW,h:leftLH},
    rightRect:{x:rX2,y:rY2,w:rightW,h:rightH},
    centerRect:{x:cX2,y:cY2,w:centW,h:centH},
    crop:{ leftX: cropLeftX, rightX: cropRightX },
    _mode:'V2-left'
  };
}

function l2_dbgHLine(x1,y,x2,color){
  if (!window.DPB_DEBUG) return;
  try{
    ctx.save();
    ctx.setLineDash([4,4]); ctx.strokeStyle=color||'#0099ff'; ctx.beginPath(); ctx.moveTo(x1,y); ctx.lineTo(x2,y); ctx.stroke(); ctx.restore();
  }catch(_){}
}

function drawL2LegsLayer(args){
  if (state?.flags?.showLegs === false) return;
  if ((byId('dpb-type')?.value || '').trim() !== 'l2') return;
  let rect1, rect2, sc, sideSel, yCrop, hCrop;
  sc = args.sc;
  sideSel = (args.side || byId('dpb-aside')?.value || 'right').toLowerCase();
  if (args.rect1 && args.rect2){
    rect1 = args.rect1;
    rect2 = args.rect2;
    const yMin = Math.min(rect1.y, rect2.y);
    const yMax = Math.max(rect1.y + rect1.h, rect2.y + rect2.h);
    yCrop = yMin; hCrop = (yMax - yMin);
  } else {
    rect1 = { x: args.x, y: args.y, w: args.w, h: args.h };
    rect2 = args.rect2 || null;
    yCrop = (typeof args.yCrop === 'number') ? args.yCrop : rect1.y;
    hCrop = (typeof args.hCrop === 'number') ? args.hCrop : rect1.h;
  }
  const Lcm = +byId('dpb-ml').value || 0;
  const colorRaw = getLegColorFromSelection();
  const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  const A = LEG_ASSETS_L2[color] || LEG_ASSETS_L2.white;
  const l2Assets = (sideSel === 'right')
    ? { left:A.left, right:A.rightL, leftL:A.leftL, rightL:A.rightL, center:A.center }
    : { left:A.leftL, right:A.right, leftL:A.leftL, rightL:A.rightL, center:A.center };
  const img = {
    left:   loadLegImage(l2Assets.left,   scheduleRedraw),
    right:  loadLegImage(l2Assets.right,  scheduleRedraw),
    leftL:  loadLegImage(l2Assets.leftL,  scheduleRedraw),
    rightL: loadLegImage(l2Assets.rightL, scheduleRedraw),
    center: loadLegImage(l2Assets.center, scheduleRedraw),
  };
  const dims = LEG_DIMS_L2_CM;
  const baseLayout = computeLegLayoutL2Rect1({
    x: rect1.x, y: rect1.y, w: rect1.w, h: rect1.h, sc, Lcm, side: sideSel, dims
  });
  let layout = baseLayout;
  const needV2 = l2_needsV2(baseLayout, rect2, sideSel);
  if (needV2){
    layout = computeLegLayoutL2Rect1_V2({
      x: rect1.x, y: rect1.y, w: rect1.w, h: rect1.h,
      sc, Lcm, side: sideSel, dims, rect2, baseCrop: baseLayout.crop
    });
    if (window.DPB_DEBUG){
      console.group('%c[L2] Switch to V2 (L-leg overflow rect2)', 'color:#e91e63;font-weight:bold');
      console.log('side:', sideSel, 'rect2:', rect2);
      console.log('V1 layout:', baseLayout);
      console.log('V2 layout:', layout);
      console.groupEnd();
    }
  }else if (window.DPB_DEBUG){
    console.groupCollapsed('%c[L2] Keep V1', 'color:#3a3');
    console.log('side:', sideSel, 'rect2:', rect2);
    console.log('V1 layout:', baseLayout);
    console.groupEnd();
  }

(function(){
    const elA = byId('dpb-gapA'), elB = byId('dpb-gapB');
    if (elA) setFieldError(elA, '');
    if (elB) setFieldError(elB, '');
    const gapA = elA ? (+String(elA.value||'').trim() || 0) : 0;
    const gapB = elB ? (+String(elB.value||'').trim() || 0) : 0;
    let okMin = true;
    if (elA && gapA < 5){ setFieldError(elA, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); okMin=false; }
    if (elB && gapB < 5){ setFieldError(elB, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5 cm ขึ้นไป'); okMin=false; }
    if(!okMin) return;
    const Lrect = layout.leftRect;
    const Rrect = layout.rightRect;
    const allowPx = 4 * sc;
    const vOverlap = (a,b)=> !(a.y + a.h <= b.y || a.y >= b.y + b.h);
    if (Lrect && Rrect){
      const vert = vOverlap(Lrect, Rrect);
      const hOverlapPx = Math.max(0, Math.min(Lrect.x+Lrect.w, Rrect.x+Rrect.w) - Math.max(Lrect.x, Rrect.x));
      if (vert && hOverlapPx > allowPx){
        const overCmRaw = (hOverlapPx - allowPx)/sc;
        const overInt    = cmOverToInt(overCmRaw);
        if (overInt > 0){
          const maxA = Math.max(5, Math.round(gapA - overInt));
          const maxB = Math.max(5, Math.round(gapB - overInt));
          if (elA) setFieldError(elA, `กรุณาลดระยะห่างลงอีก ${overInt} cm (ห้ามเกิน ${maxA} cm)`);
          if (elB) setFieldError(elB, `กรุณาลดระยะห่างลงอีก ${overInt} cm (ห้ามเกิน ${maxB} cm)`);
          return;
        }
      }
    }
  })();

  function drawCenterCropped(){
    if (!img.center || !layout.centerRect || !layout.crop) return;
    let lX = Math.max(layout.crop.leftX,  rect1.x);
    let rX = Math.min(layout.crop.rightX, rect1.x + rect1.w);
    let beamX = layout.centerRect.x;
    let beamW = layout.centerRect.w;
    if (needV2){
      const innerLeft  = Math.min(rect1.x + rect1.w, layout.leftRect.x + layout.leftRect.w);
      const innerRight = Math.max(rect1.x, layout.rightRect.x + 4*sc);
      lX = Math.max(lX, innerLeft);
      rX = Math.min(rX, innerRight);
      beamX = rect1.x;
      beamW = rect1.w;
    }
    const cw = Math.max(0, rX - lX);
    if (cw <= 0) return;
    ctx.save();
    ctx.beginPath();
    ctx.rect(lX, yCrop, cw, hCrop);
    ctx.clip();
    try{
      ctx.drawImage(img.center, beamX, layout.centerRect.y, beamW, layout.centerRect.h);
    }catch(_){}
    ctx.restore();
  }

  const alpha = (typeof args.alphaOverride === 'number') ? args.alphaOverride : 1.0;
  ctx.save();
  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  try{
    drawCenterCropped();
    if (img.right && layout.rightRect){
      ctx.drawImage(img.right, layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h);
    }
    if (img.left && layout.leftRect){
      ctx.drawImage(img.left,  layout.leftRect.x,  layout.leftRect.y,  layout.leftRect.w,  layout.leftRect.h);
    }
  }catch(_){}
  ctx.restore();
  if (window.DPB_DEBUG){
    l2_dbgRect(layout.leftRect,  '#4caf50', 'left');
    l2_dbgRect(layout.rightRect, '#4caf50', 'right');
    l2_dbgRect(layout.centerRect,'#ff9800', 'beam');
    if (rect2) l2_dbgRect(rect2, '#f06292', 'rect2');
    try{
      ctx.save();
      ctx.setLineDash([4,4]); ctx.lineWidth = 2; ctx.strokeStyle = '#00aaff';
      const showCrop = ()=>{
        if (!layout.crop){ ctx.restore(); return; }
        let lX = Math.max(layout.crop.leftX, rect1.x);
        let rX = Math.min(layout.crop.rightX, rect1.x + rect1.w);
        if (needV2){
          const innerLeft  = Math.min(rect1.x + rect1.w, layout.leftRect.x + layout.leftRect.w);
          const innerRight = Math.max(rect1.x, layout.rightRect.x + 4*sc);
          lX = Math.max(lX, innerLeft);
          rX = Math.min(rX, innerRight);
        }
        if (rX > lX){
          ctx.beginPath(); ctx.moveTo(lX, yCrop); ctx.lineTo(lX, yCrop + hCrop); ctx.stroke();
          ctx.beginPath(); ctx.moveTo(rX, yCrop); ctx.lineTo(rX, yCrop + hCrop); ctx.stroke();
        }
      };
      showCrop();
      ctx.restore();
    }catch(_){}
  }
}

function l2_needsV2(layout, rect2, side){
  if (!rect2 || !layout) return false;
  var Lrect = (side === 'right') ? layout.rightRect : layout.leftRect;
  if (!Lrect) return false;
  const topOverflow    = (Lrect.y < rect2.y - 0.5);
  const bottomOverflow = (Lrect.y + Lrect.h > rect2.y + rect2.h + 0.5);
  return (topOverflow || bottomOverflow);
}

function computeLegLayoutL2Rect1_V2({ x, y, w, h, sc, Lcm, side, dims, rect2, baseCrop }){
  // เรียกคำนวณแบบ V1 เพื่อเอาค่าพื้นฐาน
  const base = computeLegLayoutL2Rect1({ x, y, w, h, sc, Lcm, side, dims });
  
  // ดึงค่าระยะห่าง (Gap) มาใช้ เพื่อไม่ให้ขาหลุดออกนอกท็อป
  const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : {A:5, B:5};
  
  const centW = dims.center.w * sc, centH = dims.center.h * sc;
  
  if (side === 'right'){
    const rLW = dims.rightL.w * sc, rLH = dims.rightL.h * sc;
    const gapRight = Math.max(5, gaps.B);

    // [แก้จุดที่ 1] ตำแหน่งขาขวา: ให้คำนวณจากขอบขวาของ rect2 เข้ามาตามระยะ Gap (ไม่ใช้สูตร Center)
    // สูตร: ขอบขวาของไม้ L - ระยะห่าง - ความกว้างขา
    const rightLX = rect2 
      ? (rect2.x + rect2.w - (gapRight * sc) - rLW) 
      : base.rightRect.x;
      
    const rightLY = rect2 ? (rect2.y + (rect2.h - rLH)/2) : base.rightRect.y;

    const leftW = dims.left.w * sc, leftH = dims.left.h * sc;
    const leftX = base.leftRect.x;
    const leftY = rightLY;

    // [แก้จุดที่ 2] ตำแหน่งคานกลาง: ให้กลับมาอิงกึ่งกลางโต๊ะหลัก (Rect1) เหมือน V1
    // ของเดิม (ผิด): leftX + (leftW - centW)/2;  <-- อันนี้มันไปอิงขาซ้าย
    // ของใหม่ (ถูก): x + (w - centW)/2;          <-- อิงกึ่งกลางโต๊ะหลัก
    const centerX = x + (w - centW)/2;
    const centerY = leftY + (leftH - centH)/2;

    return {
      leftRect:   { x:leftX,  y:leftY,  w:leftW,   h:leftH  },
      rightRect:  { x:rightLX,y:rightLY,w:rLW,     h:rLH    },
      centerRect: { x:centerX,y:centerY,w:centW,   h:centH  },
      crop: baseCrop || base.crop
    };

  } else { 
    // ฝั่งซ้าย (Left Side) ก็ต้องแก้เหมือนกัน
    const lLW = dims.leftL.w * sc, lLH = dims.leftL.h * sc;
    const gapLeft = Math.max(5, gaps.A);

    // [แก้จุดที่ 1] ตำแหน่งขาซ้าย: อิงขอบซ้ายของ rect2 + ระยะ Gap
    const leftLX = rect2 
      ? (rect2.x + (gapLeft * sc)) 
      : base.leftRect.x;

    const leftLY = rect2 ? (rect2.y + (rect2.h - lLH)/2) : base.leftRect.y;

    const rightW = dims.right.w * sc, rightH = dims.right.h * sc;
    const rightX = base.rightRect.x;
    const rightY = leftLY;

    // [แก้จุดที่ 2] ตำแหน่งคานกลาง: อิงกึ่งกลางโต๊ะหลัก
    const centerX = x + (w - centW)/2;
    const centerY = rightY + (rightH - centH)/2;

    return {
      leftRect:   { x:leftLX, y:leftLY, w:lLW,   h:lLH    },
      rightRect:  { x:rightX, y:rightY, w:rightW,h:rightH },
      centerRect: { x:centerX,y:centerY,w:centW, h:centH  },
      crop: baseCrop || base.crop
    };
  }
}

function l2_dbgRect(r, color='#00bcd4', label=''){
  if (!r || !window.DPB_DEBUG) return;
  try{
    ctx.save();
    ctx.globalAlpha = 1;
    ctx.setLineDash([6,4]); ctx.lineWidth = 2; ctx.strokeStyle = color;
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    if (label){
      ctx.font = '500 12px Prompt, sans-serif';
      ctx.fillStyle = color; ctx.fillText(label, r.x + 4, r.y - 6);
    }
    ctx.restore();
  }catch(_){}
}

function isLDeskType(){
  const t = (byId('dpb-type')?.value || '').trim().toLowerCase();
  return (t === 'l2' || t === 'l3');
}

const L_ALLOWED_LEG_KEYS = ['square-white','square-black'];

function isLegAllowedForLDesk(key, name){
  const k = String(key||'').toLowerCase();
  const n = String(name||'').toLowerCase();
  if (L_ALLOWED_LEG_KEYS.includes(k)) return true;
  if (/\bsquare\b|เหลี่ยม/.test(k) || /\bsquare\b|เหลี่ยม/.test(n)) return true;
  return false;
}

function isLegAllowedForType(legRow, t){
  const key   = String(legRow?.key||'').toLowerCase();
  const name  = String(legRow?.name||'').toLowerCase();
  const label = (key + ' ' + name);

  // ตัวช่วยเช็ค: เป็นขากลม? เป็นสีเทา?
  const isCircle = label.includes('circle') || label.includes('กลม');
  const isGrey   = label.includes('grey') || label.includes('gray') || label.includes('เทา');

  // --- 1. กลุ่ม custom_workspace ---
  // กฎ: ให้เห็นแค่ 3 ตัวนี้เท่านั้น (dual_circle_grey, dual_circle_white, dual_circle_black)
  if (t === 'custom_workspace') {
    if (label.includes('dual_circle_grey')) return true;
    if (label.includes('dual_circle_white')) return true;
    if (label.includes('dual_circle_black')) return true;
    return false; // ตัวอื่นห้ามเห็น
  }

  // --- 2. กลุ่ม custom (ชื่อ type 'custom' เพียวๆ) ---
  // กฎ: เห็น Square ได้, เห็น Circle (ขาว/ดำ) ได้, ห้ามเห็น Grey, และ **ห้ามเห็น Dual**
  if (t === 'custom') {
    // [เพิ่มใหม่] ถ้าเจอคำว่า dual ให้บล็อกทันที (เพื่อกัน dual_circle ของ workspace หลุดมา)
    if (label.includes('dual')) return false;

    if (isCircle) {
      // ถ้าเป็นขากลม ห้ามสีเทา (เหลือแค่ ขาว/ดำ)
      if (isGrey) return false;
      return true;
    }
    // ถ้าเป็น Square ผ่านปกติ
    return true;
  }

  // --- 3. กลุ่ม custom_single, custom_manual และโต๊ะปกติ ---
  // กฎ: ไม่เห็น Circle เลย (เห็นแค่ Square)
  if (t === 'custom_single' || t === 'custom_manual' || isLType(t) || isSingleType(t)){
    // ถ้าเจอขากลม ปัดตกทันที (ซึ่ง dual_circle ก็จะโดนปัดตกตรงนี้ด้วยเพราะมีคำว่า circle)
    if (isCircle) return false;
  }

  // Default: กรณี type อื่นๆ ที่ไม่ได้ระบุ ให้ผ่านหมด
  return true;
}

function coerceLegSelectionToAllowed(currentKey, list, t){
  const allowed = list.filter(row => isLegAllowedForType(row, t));
  if (!allowed.length) return currentKey;
  const ok = allowed.some(r => String(r.key) === String(currentKey));
  if (ok) return currentKey;
  const pickSQW = allowed.find(r => String(r.key).toLowerCase().includes('square-white'));
  return (pickSQW ? pickSQW.key : allowed[0].key);
}

function getDeskType(){
  const el = document.getElementById('dpb-type');
  return el ? String(el.value).trim().toLowerCase() : '';
}

function isLType(t){ t = t || getDeskType(); return t === 'l2' || t === 'l3'; }

function isSingleType(t){ t = t || getDeskType(); return t === 'single'; }

function getLAside(){
  const el = document.getElementById('dpb-aside');
  return el ? el.value : 'right';
}



const LEG_DIMS_MANUAL_CM = {
  right:     { w: 49.3, h: 45.0 }, 
  left:      { w: 49.3, h: 45.0 }, 
  center:    { w: 140.0,h: 11.2 }, 
  connector: { w: 4.3,  h: 0.5  }, 
  crank:     { w: 4.3,  h: 20.0 } 
};


function drawManualDeskLegsLayer({ x, y, w, h, sc, topClip, alphaOverride }) {
  if (state?.flags?.showLegs === false) return;

  const colorRaw = getLegColorFromSelection();
  const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  const A = MANUAL_DESK_ASSETS[legColor] || MANUAL_DESK_ASSETS.white;
  
  const imgR = loadLegImage(A.right, scheduleRedraw);
  const imgL = loadLegImage(A.left, scheduleRedraw);
  const imgC = loadLegImage(A.center, scheduleRedraw);
  const imgConn = loadLegImage(A.connector, scheduleRedraw);
  const imgCrank = loadLegImage(A.crank, scheduleRedraw);

  if (!imgR || !imgL || !imgC || !imgConn || !imgCrank) {
     return;
  }

  const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
  const gapLeft = Math.max(5, gaps.A);
  const gapRight = Math.max(5, gaps.B);

  const dims = LEG_DIMS_MANUAL_CM;
  const rW = dims.right.w * sc, rH = dims.right.h * sc;
  const lW = dims.left.w * sc,  lH = dims.left.h * sc;
  const cW = dims.center.w * sc,cH = dims.center.h * sc;
  const connW = dims.connector.w * sc, connH = dims.connector.h * sc;
  const crankW = dims.crank.w * sc,    crankH = dims.crank.h * sc;

  const leftX  = x + (gapLeft * sc);
  const leftY  = y + (h - lH) / 2;
  
  const rightX = x + w - (gapRight * sc) - rW;
  const rightY = y + (h - rH) / 2;
  
  const centerX = x + (w - cW) / 2;
  const centerY = y + (h - cH) / 2;

  const connX = (rightX + rW) - connW;
  const connY = rightY + rH;

  const tableBottom = y + h; 
  const distFromTableBottom = 7.35 * sc;
  const crankBottomY = tableBottom + distFromTableBottom;
  const crankY = crankBottomY - crankH;
  const crankX = (rightX + rW) - crankW; 

  let alpha = (legColor === 'black') ? 1.00 : 1.00;
  if (typeof alphaOverride === 'number') alpha = alphaOverride;

  ctx.save();
  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));

 
  ctx.drawImage(imgCrank, crankX, crankY, crankW, crankH);

  if (topClip && topClip.enable) {
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii);
    } else {
      roundedPath(ctx, topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii[0], topClip.radii[1], topClip.radii[2], topClip.radii[3]);
    }
    ctx.clip();
  }

  ctx.save();
  const clipL = leftX;
  const clipR = rightX + rW;
  const clipW = clipR - clipL;
  if(clipW > 0){
      ctx.beginPath();
      ctx.rect(clipL, y, clipW, h); 
      ctx.clip(); 
      ctx.drawImage(imgC, centerX, centerY, cW, cH);
  }
  ctx.restore();
  ctx.drawImage(imgL, leftX, leftY, lW, lH);
  ctx.drawImage(imgR, rightX, rightY, rW, rH);
  ctx.drawImage(imgConn, connX, connY, connW, connH);
  ctx.restore();
}




const LEG_DIMS_SINGLE_MOTOR_CM = {
  right:  { w: 92.6, h: 50.0 }, 
  left:   { w: 50.0, h: 50.0 }, 
  center: { w: 124.4, h: 13.9 } 
};

function drawSingleMotorLegsLayer({ x, y, w, h, sc, topClip, alphaOverride }) {
  if (state?.flags?.showLegs === false) return;
  
  const type = byId('dpb-type')?.value;
  if (type !== 'custom_single') return;

  const colorRaw = getLegColorFromSelection(); 
  const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  const A = SINGLE_MOTOR_ASSETS[legColor] || SINGLE_MOTOR_ASSETS.white;
  
  const imgR = loadLegImage(A.right, scheduleRedraw);
  const imgL = loadLegImage(A.left, scheduleRedraw);
  const imgC = loadLegImage(A.center, scheduleRedraw);

  if (!imgR || !imgL || !imgC) return;

  const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
  const gapLeft = Math.max(5, gaps.A);
  const gapRight = Math.max(5, gaps.B);

  const dims = LEG_DIMS_SINGLE_MOTOR_CM;
  
  const rW = dims.right.w * sc;
  const rH = dims.right.h * sc;
  const lW = dims.left.w * sc;
  const lH = dims.left.h * sc;
  const cW = dims.center.w * sc;
  const cH = dims.center.h * sc;

  const leftX  = x + (gapLeft * sc);
  const rightX = x + w - (gapRight * sc) - rW;
  const centerX = x + (w - cW) / 2;

  const leftY   = y + (h - lH) / 2;
  const rightY  = y + (h - rH) / 2;
  const centerY = y + (h - cH) / 2;

  let alpha = (legColor === 'black') ? 1.00 : 1.00;
  if (typeof alphaOverride === 'number') alpha = alphaOverride;

  ctx.save();
  
  if (topClip && topClip.enable) {
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii);
    } else {
      roundedPath(ctx, topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii[0], topClip.radii[1], topClip.radii[2], topClip.radii[3]);
    }
    ctx.clip();
  }

  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  ctx.save();
  const clipLeft = leftX;
  const clipWidth = (rightX + rW) - leftX;
  
  if (clipWidth > 0) {
    ctx.beginPath();
    ctx.rect(clipLeft, y, clipWidth, h); 
    ctx.clip();
    ctx.drawImage(imgC, centerX, centerY, cW, cH);
  }
  ctx.restore();
  ctx.drawImage(imgL, leftX, leftY, lW, lH);
  ctx.drawImage(imgR, rightX, rightY, rW, rH);
  ctx.restore();
}



const LEG_DIMS_WORKSPACE_CM = {
  right:  { w: 92.6, h: 50.0 }, 
  left:   { w: 50.0, h: 50.0 }, 
  center: { w: 124.4, h: 13.9 } 
};

function drawWorkSpaceLegsLayer({ x, y, w, h, sc, topClip, alphaOverride }) {
  if (state?.flags?.showLegs === false) return;
  
  const type = byId('dpb-type')?.value;
  if (type !== 'custom_single') return;

  const colorRaw = getLegColorFromSelection(); 
  const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
  const A = WORKSPACE_ASSETS[legColor] || WORKSPACE_ASSETS.white;
  
  const imgR = loadLegImage(A.right, scheduleRedraw);
  const imgL = loadLegImage(A.left, scheduleRedraw);
  const imgC = loadLegImage(A.center, scheduleRedraw);

  if (!imgR || !imgL || !imgC) return;

  const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
  const gapLeft = Math.max(5, gaps.A);
  const gapRight = Math.max(5, gaps.B);

  const dims = LEG_DIMS_WORKSPACE_CM;
  
  const rW = dims.right.w * sc;
  const rH = dims.right.h * sc;
  const lW = dims.left.w * sc;
  const lH = dims.left.h * sc;
  const cW = dims.center.w * sc;
  const cH = dims.center.h * sc;

  const leftX  = x + (gapLeft * sc);
  const rightX = x + w - (gapRight * sc) - rW;
  const centerX = x + (w - cW) / 2;

  const leftY   = y + (h - lH) / 2;
  const rightY  = y + (h - rH) / 2;
  const centerY = y + (h - cH) / 2;

  let alpha = (legColor === 'black') ? 1.00 : 1.00;
  if (typeof alphaOverride === 'number') alpha = alphaOverride;

  ctx.save();
  
  if (topClip && topClip.enable) {
    ctx.beginPath();
    if (ctx.roundRect) {
      ctx.roundRect(topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii);
    } else {
      roundedPath(ctx, topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii[0], topClip.radii[1], topClip.radii[2], topClip.radii[3]);
    }
    ctx.clip();
  }

  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  ctx.save();
  const clipLeft = leftX;
  const clipWidth = (rightX + rW) - leftX;
  
  if (clipWidth > 0) {
    ctx.beginPath();
    ctx.rect(clipLeft, y, clipWidth, h); 
    ctx.clip();
    ctx.drawImage(imgC, centerX, centerY, cW, cH);
  }
  ctx.restore();
  ctx.drawImage(imgL, leftX, leftY, lW, lH);
  ctx.drawImage(imgR, rightX, rightY, rW, rH);
  ctx.restore();
}

function pxToCm(px){ return px / 10; }

function cmToPx(cm, sc){ return cm * sc; }

function drawSingleLegLayer({ x, y, w, h, sc, topClip, alphaOverride }){
  if (state?.flags?.showLegs === false) return;
  const type = byId('dpb-type')?.value || '';
  if (type !== 'single') return;
const colorRaw = getLegColorFromSelection();
const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
 const A = SINGLE_LEG_ASSETS[color] || SINGLE_LEG_ASSETS.white;
  const imgLeg   = loadLegImage(A.leg,   scheduleRedraw);
  if(!imgLeg) return;
  const legWcm   = pxToCm(imgLeg.naturalWidth);
  const legHcm   = pxToCm(imgLeg.naturalHeight);
  const legW   = cmToPx(legWcm, sc);
  const legH   = cmToPx(legHcm, sc);
  const legX = x + (w - legW)/2;
  const legY = y + (h - legH)/2;
  ctx.save();
  if(topClip && topClip.enable){
    ctx.beginPath();
    if (ctx.roundRect){
      ctx.roundRect(topClip.x, topClip.y, topClip.w, topClip.h, topClip.radii);
    }else{
      roundedPath(ctx, topClip.x, topClip.y, topClip.w, topClip.h,
        topClip.radii[0], topClip.radii[1], topClip.radii[2], topClip.radii[3]);
    }
    ctx.clip();
  }
  let alpha = (color === 'black') ? 1.00 : 1.00;
  if (typeof alphaOverride === 'number') alpha = alphaOverride;
  ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
  try { ctx.drawImage(imgLeg,   legX,   legY,   legW,   legH); }   catch(e){}
  ctx.restore();
}

(function(){
  const $btn  = document.getElementById('dpb-leggap-toggle');
  const $wrap = document.getElementById('dpb-leggap-fields');
  if(!$btn || !$wrap) return;
  $btn.addEventListener('click', ()=>{
    const open = !$wrap.classList.contains('is-open');
    $wrap.classList.toggle('is-open', open);
    const caret = $btn.querySelector('.dpb-caret');
    if(caret) caret.classList.toggle('is-open', open);
    $btn.setAttribute('aria-expanded', open?'true':'false');
    $wrap.setAttribute('aria-hidden', open?'false':'true');
  });
})();

(function(){
  const $gapA = byId('dpb-gapA');
  const $gapB = byId('dpb-gapB');
  const $gapALabel = byId('dpb-gapA-label');
  const $gapBLabel = byId('dpb-gapB-label');
  const $wrap = byId('dpb-leggap-fields');
  window.__dpb_legGapPrefs = window.__dpb_legGapPrefs || {
    useDefaultsCustom: false,
    useDefaultsL2:      true,
    useDefaultsL3:      false
  };

  function _setFieldError(el, msg){
    el.classList.toggle('dpb-invalid', !!msg);
    const note = el.parentElement.querySelector('.dpb-field-note');
    if(note){
      note.textContent = msg || '';
      note.style.display = msg ? '' : 'none';
    }
  }

  function dpb_legGap_min5_guard(){
    [$gapA,$gapB].forEach($in=>{
      if(!$in) return;
      const v = +($in.value||0);
      if (v<5){
        _setFieldError($in, 'แนะนำให้ขอบโต๊ะมีระยะห่าง 5cm ขึ้นไป');
      } else {
        _setFieldError($in, '');
      }
    });
  }

  function dpb_syncLegGapUIVisibility(){
    const t = (byId('dpb-type')?.value||'').trim();
    const side = (byId('dpb-aside')?.value||'right').trim();

    const show = (t==='custom' || t==='custom_manual' || t==='custom_single' || t==='l2' || t==='l3' || t==='custom_workspace') && t!=='single';
    
    if($wrap) $wrap.style.display = show ? '' : 'none';
    if(!show) return;

    if(t==='custom' || t==='custom_manual' || t==='custom_workspace' || t==='custom_single'){
      $gapALabel.textContent = 'ขาซ้าย (cm)';
      $gapBLabel.textContent = 'ขาขวา (cm)';
    }else if(t==='l2'){
      if(side==='right'){
        $gapALabel.textContent = 'ขาซ้าย (cm)';
        $gapBLabel.textContent = 'ขาขวาแอล (cm)';
      }else{
        $gapALabel.textContent = 'ขาซ้ายแอล (cm)';
        $gapBLabel.textContent = 'ขาขวา (cm)';
      }
    }else if(t==='l3'){
      if(side==='right'){
        $gapALabel.textContent = 'ขาซ้าย (cm)';
        $gapBLabel.textContent = 'ขาล่างขวา (cm)';
      }else{
        $gapALabel.textContent = 'ขาขวา (cm)';
        $gapBLabel.textContent = 'ขาล่างซ้าย (cm)';
      }
    }
  }

  function dpb_applyL2DefaultsIfNeeded(){
    if(!window.__dpb_legGapPrefs.useDefaultsL2) return;
    const t = (byId('dpb-type')?.value||'').trim();
    if(t!=='l2') return;
    const Lcm = +byId('dpb-ml').value || 0;
    let def = 5;
    if(Lcm>=120 && Lcm<=180) def=5;
    else if(Lcm>=181 && Lcm<=190) def=15;
    else if(Lcm>=191 && Lcm<=200) def=25;
    if(byId('dpb-gapA') && byId('dpb-gapB')){
      byId('dpb-gapA').value = def;
      byId('dpb-gapB').value = def;
    }
  }

  function dpb_resetGapsWhenLeavingL2(prevType){
    const now = (byId('dpb-type')?.value||'').trim();
    if(prevType==='l2' && (now==='custom' || now==='custom_manual' || now==='custom_workspace' || now==='custom_single' || now==='l3')){
      if($gapA) $gapA.value = 5;
      if($gapB) $gapB.value = 5;
    }
  }

  (function dpb_initLegGaps(){
    if($gapA && !$gapA.dataset.__inited){ $gapA.value = 5; $gapA.dataset.__inited = '1'; }
    if($gapB && !$gapB.dataset.__inited){ $gapB.value = 5; $gapB.dataset.__inited = '1'; }
    dpb_legGap_min5_guard();
    dpb_syncLegGapUIVisibility();
    dpb_applyL2DefaultsIfNeeded();
  })();

  let __prevType = (byId('dpb-type')?.value||'').trim();
  ['change','input'].forEach(ev=>{
    if($gapA) $gapA.addEventListener(ev, dpb_legGap_min5_guard);
    if($gapB) $gapB.addEventListener(ev, dpb_legGap_min5_guard);
  });
  ['dpb-type','dpb-aside','dpb-ml'].forEach(id=>{
    const el = byId(id);
    if(!el) return;
    el.addEventListener('change', ()=>{
      dpb_syncLegGapUIVisibility();
      dpb_applyL2DefaultsIfNeeded();
      dpb_resetGapsWhenLeavingL2(__prevType);
      __prevType = (byId('dpb-type')?.value||'').trim();
      if(typeof window.scheduleRedraw === 'function'){
        scheduleRedraw();
      }
    });
  });

  window.dpb_getLegGaps = function(){
    const A = Math.max(5, +(byId('dpb-gapA')?.value||5));
    const B = Math.max(5, +(byId('dpb-gapB')?.value||5));
    return { A, B };
  };
})();

(function(){
  function setErr(el, msg){
    el.classList.toggle('dpb-invalid', !!msg);
    let note = el.parentElement.querySelector('.dpb-field-note');
    if(!note){ note = document.createElement('div'); note.className='dpb-field-note'; el.parentElement.appendChild(note); }
    note.textContent = msg||''; note.style.display = msg ? '' : 'none';
  }

function _checkOverlap_Custom(Lcm, gapL, gapR){
  const res = dpb_calcCustomOverlap(Lcm, gapL, gapR, 4);
  if (!res.ok){
    return {
      ok:false,
      msgA:`กรุณาลดระยะห่างลงอีก ${res.overCm} cm (ห้ามเกิน ${res.maxA} cm)`,
      msgB:`กรุณาลดระยะห่างลงอีก ${res.overCm} cm (ห้ามเกิน ${res.maxB} cm)`
    };
  }
  return { ok:true };
}

function _checkOverlap_L2(side, Lcm, gapA, gapB){
  const allow = 4;
  let wA, wB;
  if (String(side).toLowerCase()==='right'){
    wA = LEG_DIMS_L2_CM.left.w;
    wB = LEG_DIMS_L2_CM.rightL.w;
  }else{
    wA = LEG_DIMS_L2_CM.leftL.w;
    wB = LEG_DIMS_L2_CM.right.w;
  }
  const threshold = Lcm - (wA + wB) + allow;
  const over = (gapA + gapB) - threshold;
  if (over > 0){
    const overStr = over.toFixed(1);
    const maxA = Math.max(5, Math.round(gapA - over));
    const maxB = Math.max(5, Math.round(gapB - over));
    return {
      ok:false,
      msgA:`กรุณาลดระยะห่างลงอีก ${overStr} cm (ห้ามเกิน ${maxA} cm)`,
      msgB:`กรุณาลดระยะห่างลงอีก ${overStr} cm (ห้ามเกิน ${maxB} cm)`
    };
  }
  return { ok:true };
}

  function _checkL3_Rules(t, side, gapA, gapB){
    const res = { ok:true, msgA:'', msgB:'' };
    const minOKA = gapA>=5, minOKB=gapB>=5;
    if(!minOKA){ res.ok=false; res.msgA='แนะนำให้ขอบโต๊ะมีระยะห่าง5cmขึ้นไป'; }
    if(!minOKB){ res.ok=false; res.msgB='แนะนำให้ขอบโต๊ะมีระยะห่าง5cmขึ้นไป'; }
    if(!res.ok) return res;
    if(side==='left'){
      const allowA = 11.5;
      if(gapA > (50 + allowA)){ res.ok=false; res.msgA = 'กรุณาลดระยะห่างลงเพื่อไม่ให้ซ้อนเกิน 11.5 ซม.'; }
      if(gapB > 50){ res.ok=false; res.msgB = 'ขาล่างซ้ายกับขาบนซ้ายห้ามซ้อนกัน'; }
      return res;
    }else{
      const allowA = 11.5;
      if(gapA > (50 + allowA)){ res.ok=false; res.msgA = 'กรุณาลดระยะห่างลงเพื่อไม่ให้ซ้อนเกิน 11.5 ซม.'; }
      if(gapB > 50){ res.ok=false; res.msgB = 'ขาล่างขวากับขาบนขวาห้ามซ้อนกัน'; }
      return res;
    }
  }

  window.dpb_validateLegGaps = function(){
    const t    = (byId('dpb-type')?.value||'').trim();
    if(!t || t==='single') return { ok:true, messages:[] };
    const side = (byId('dpb-aside')?.value||'right').trim();
    const Lcm  = +byId('dpb-ml').value || 0;
    const $A = byId('dpb-gapA'), $B = byId('dpb-gapB');
    const gapA = Math.max(5, +($A?.value||5));
    const gapB = Math.max(5, +($B?.value||5));
    let ok = true, msgs = [];
    if($A) setErr($A,''); if($B) setErr($B,'');
    
    if(t==='custom' || t==='custom_manual' || t==='custom_single' || t==='custom_workspace'){
      const chk = _checkOverlap_Custom(Lcm, gapA, gapB);
      if(!chk.ok){ ok=false; if($A) setErr($A, chk.msgA||''); if($B) setErr($B, chk.msgB||''); msgs.push(chk.msgA||chk.msgB); }
    } else if(t==='l2'){
      const chk = _checkOverlap_L2(side, Lcm, gapA, gapB);
      if(!chk.ok){ ok=false; if($A) setErr($A, chk.msgA||''); if($B) setErr($B, chk.msgB||''); msgs.push(chk.msgA||chk.msgB); }
    } else if(t==='l3'){
      const chk = _checkL3_Rules(t, side, gapA, gapB);
      if(!chk.ok){ ok=false; if($A) setErr($A, chk.msgA||''); if($B) setErr($B, chk.msgB||''); msgs.push(chk.msgA||chk.msgB); }
    }
    try{
      document.dispatchEvent(new CustomEvent('dpb:validation-changed',{
        detail:{ ok, messages: msgs.slice() }
      }));
    }catch(_){}
    return { ok, messages: msgs };
  };

  document.addEventListener('dpb:validation-changed', function(e){
  });
})();

(function(){
  function _q(id){ return document.getElementById(id); }

  function dpb_getGapInputs(){
    var ids = ['dpb-gapA','dpb-gapB','dpb-gap-left','dpb-gap-right','dpb-gapL','dpb-gapR'];
    var a=null,b=null;
    for(var i=0;i<ids.length;i++){
      var el=_q(ids[i]);
      if(el && el.offsetParent!==null){
        if(!a) a=el; else if(!b && el!==a) { b=el; break; }
      }
    }
    return {a:a,b:b};
  }

  function dpb_resetGapsToDefaults(){
    var type = (_q('dpb-type')?.value || '').trim().toLowerCase();
    var pair = dpb_getGapInputs(); if(!pair.a || !pair.b) return;
    if (type === 'l2'){
      var done = false;
      try{
        if (typeof window.dpb_applyL2DefaultsIfNeeded === 'function'){
          window.dpb_applyL2DefaultsIfNeeded(true);
          done = true;
        }
      }catch(_){}
      if(!done){
        var Lcm = +(_q('dpb-ml')?.value || 0);
        var def = (Lcm>=191 && Lcm<=200)?25 : (Lcm>=181 && Lcm<=190)?15 : 5;
        pair.a.value = String(def);
        pair.b.value = String(def);
      }
    }else{
      pair.a.value = '5';
      pair.b.value = '5';
    }
    if (typeof setFieldError === 'function'){
      setFieldError(pair.a, '');
      setFieldError(pair.b, '');
    }
    try{
      pair.a.dispatchEvent(new Event('input',{bubbles:true}));
      pair.b.dispatchEvent(new Event('input',{bubbles:true}));
    }catch(_){}
    try{ scheduleRedraw(); }catch(_){}
  }

  function dpb_centerGaps(){
    var type = (_q('dpb-type')?.value || '').trim().toLowerCase();
    if (type === 'l3') return;
    var pair = dpb_getGapInputs(); if(!pair.a || !pair.b) return;
    var vA = +((pair.a.value||'').toString().trim()||0);
    var vB = +((pair.b.value||'').toString().trim()||0);
    if (isNaN(vA)) vA = 0; if (isNaN(vB)) vB = 0;
    var avg = (vA + vB)/2;
    if (avg < 5) avg = 5;
    pair.a.value = String(avg);
    pair.b.value = String(avg);
    if (typeof setFieldError === 'function'){
      setFieldError(pair.a, '');
      setFieldError(pair.b, '');
    }
    try{
      pair.a.dispatchEvent(new Event('input',{bubbles:true}));
      pair.b.dispatchEvent(new Event('input',{bubbles:true}));
    }catch(_){}
    try{ scheduleRedraw(); }catch(_){}
  }

  function dpb_updateGapButtonsVisibility(){
    var type = (_q('dpb-type')?.value || '').trim().toLowerCase();
    var centerBtn = _q('dpb-gap-center');
    if (centerBtn){
      centerBtn.style.display = (type==='custom' || type==='custom_manual' || type==='custom_single' || type==='l2' || type==='custom_workspace') ? '' : 'none';
    }
  }

  function dpb_bindGapButtons(){
    var resetBtn  = _q('dpb-gap-reset');
    var centerBtn = _q('dpb-gap-center');
    if (resetBtn && !resetBtn._dpbBound){
      resetBtn._dpbBound = true;
      resetBtn.addEventListener('click', dpb_resetGapsToDefaults);
    }
    if (centerBtn && !centerBtn._dpbBound){
      centerBtn._dpbBound = true;
      centerBtn.addEventListener('click', dpb_centerGaps);
    }
    dpb_updateGapButtonsVisibility();
  }

  dpb_bindGapButtons();
  try{
    var tSel = _q('dpb-type'); if (tSel) tSel.addEventListener('change', dpb_updateGapButtonsVisibility);
    var aSel = _q('dpb-aside'); if (aSel) aSel.addEventListener('change', dpb_updateGapButtonsVisibility);
  }catch(_){}

  window.dpb_resetGapsToDefaults = dpb_resetGapsToDefaults;
  window.dpb_centerGaps = dpb_centerGaps;
  window.dpb_updateGapButtonsVisibility = dpb_updateGapButtonsVisibility;
})();

function optionPositionChoices(){
  return [
    { value:'main',  label:'ท็อปด้านบน' },
    { value:'left',  label:'ท็อปฝั่งซ้าย' },
    { value:'right', label:'ท็อปฝั่งขวา' }
  ];
}

function placementLabelPack({ pos }){
  const p = (pos || 'main').toLowerCase();
  const yName = 'ระยะห่าง (cm)';
  const xName = 'ระยะห่าง (cm)';
  if(p === 'main'){
    return {
      vLabel: 'การจัดวางแนวตั้ง',
      vChoices: [
        { value:'top',    label:'ด้านบน' },
        { value:'bottom', label:'ด้านล่าง' }
      ],
      yName,
      hLabel: 'การจัดวางแนวนอน',
      hChoices: [
        { value:'left',   label:'ด้านซ้าย' },
        { value:'center', label:'ตรงกลาง' },
        { value:'right',  label:'ด้านขวา' }
      ],
      xName
    };
  }
  if(p === 'left'){
    return {
      vLabel: 'การจัดวางแนวตั้งจาก',
      vChoices: [
        { value:'left',   label:'ด้านซ้าย' }
      ],
      yName,
      hLabel: 'การจัดวางแนวนอน',
      hChoices: [
        { value:'left',   label:'ด้านล่าง' },
        { value:'center', label:'ตรงกลาง' },
        { value:'right',  label:'ด้านบน' }
      ],
      xName
    };
  }
  if(p === 'right'){
    return {
      vLabel: 'การจัดวางแนวตั้งจาก',
      vChoices: [
        { value:'right',  label:'ด้านขวา' }
      ],
      yName,
      hLabel: 'การจัดวางแนวนอน',
      hChoices: [
        { value:'left',   label:'ด้านบน' },
        { value:'center', label:'ตรงกลาง' },
        { value:'right',  label:'ด้านล่าง' }
      ],
      xName
    };
  }
  return {};
}

function optsHtml(choices, selectedValue){
  return choices.map(c=>`<option value="${c.value}" ${String(selectedValue)===String(c.value)?'selected':''}>${c.label}</option>`).join('');
}

function applyPackToCard(card, cfg){
  const pack = placementLabelPack({ pos: cfg.pos || 'main' });
  const labV = card.querySelector('[data-role="label-v"]');
  const labVLen = card.querySelector('[data-role="label-vlen"]');
  const labH = card.querySelector('[data-role="label-h"]');
  const labHLen = card.querySelector('[data-role="label-hlen"]');
  const selFrom = card.querySelector('select[name="from"]');
  const selPlace = card.querySelector('select[name="place"]');
  if(labV)    labV.textContent = pack.vLabel;
  if(labVLen) labVLen.textContent = pack.yName;
  if(labH)    labH.textContent = pack.hLabel;
  if(labHLen) labHLen.textContent = pack.xName;
  if(selFrom){
    const have = pack.vChoices.some(x=>x.value===cfg.from);
    selFrom.innerHTML = optsHtml(pack.vChoices, have?cfg.from:pack.vChoices[0].value);
    if(!have) cfg.from = pack.vChoices[0].value;
  }
  if(selPlace){
    const have = pack.hChoices.some(x=>x.value===cfg.place);
    selPlace.innerHTML = optsHtml(pack.hChoices, have?cfg.place:pack.hChoices[0].value);
    if(!have) cfg.place = pack.hChoices[0].value;
  }
}

function rebuildPosSelectInCard(card, cfg){
  const posWrap = card.querySelector('select[name="pos"]');
  if(!posWrap) return;
  const list = optionPositionChoices();
  const have = list.some(x=>x.value===cfg.pos);
  posWrap.innerHTML = list.map(p=>`<option value="${p.value}" ${have && cfg.pos===p.value ? 'selected':''}>${p.label}</option>`).join('');
  if(!have){
    cfg.pos = 'main';
    posWrap.value = 'main';
  }
}

function refreshAllCartForms(){
  const cards = cartBody.querySelectorAll('.dpb-cart-item');
  cards.forEach(card=>{
    const key = card.dataset.key;
    const index = Number(card.dataset.index);
    const cfg = state.optConfig[key]?.[index];
    if(!cfg) return;
    rebuildPosSelectInCard(card, cfg);
    applyPackToCard(card, cfg);
  });
  validateOptionPlacements();
  scheduleRedraw();
}

const $rmModal     = document.getElementById('dpb-remove-confirm');
const $rmBackdrop  = document.getElementById('dpb-remove-confirm-backdrop');
const $rmTitle     = document.getElementById('dpb-remove-confirm-title');
const $rmNo        = $rmModal?.querySelector('.dpb-mini-confirm__no');
const $rmYes       = $rmModal?.querySelector('.dpb-mini-confirm__yes');

function showRemoveGroupConfirm(onYes){
  if(window.confirm('คุณต้องการลบรายการนี้ทั้งหมดหรือไม่?')){
    if(typeof onYes === 'function') onYes();
  }
}

let _rmOnYes = null;
let _rmOnNo  = null;

function showMiniRemoveConfirm({ title, onYes, onNo }){
  if(!$rmModal) return;
  if($rmTitle && title) $rmTitle.textContent = String(title);
  _rmOnYes = typeof onYes === 'function' ? onYes : null;
  _rmOnNo  = typeof onNo  === 'function' ? onNo  : null;
  $rmModal.setAttribute('aria-hidden','false');
  $rmModal.classList.add('is-open');
}

function hideMiniRemoveConfirm(){
  if(!$rmModal) return;
  $rmModal.classList.remove('is-open');
  $rmModal.setAttribute('aria-hidden','true');
  _rmOnYes = null; _rmOnNo = null;
}

$rmBackdrop?.addEventListener('click', hideMiniRemoveConfirm);
$rmNo?.addEventListener('click', ()=>{ try{ _rmOnNo?.(); }finally{ hideMiniRemoveConfirm(); } });
$rmYes?.addEventListener('click', ()=>{ try{ _rmOnYes?.(); }finally{ hideMiniRemoveConfirm(); } });
document.addEventListener('keydown', (e)=>{
  if(e.key==='Escape' && $rmModal?.classList.contains('is-open')) hideMiniRemoveConfirm();
});

// ============================================================
// [PART 3 MODIFIED] Validation Logic (ส่งข้อความ 2 แบบ)
// ============================================================
function validateOptionPlacements(){
  state.validation = state.validation || { ok:true, messages:[] };
  let ok = true;
  
  Object.keys(state.optConfig).forEach(key => {
    const arr = state.optConfig[key] || [];
    const op = (state.meta.options || []).find(o => o.key === key) || {};
    const baseName = op.name || key;

    arr.forEach((cfg, index) => {
      const card = cartBody.querySelector(`.dpb-cart-item[data-key="${CSS.escape(key)}"][data-index="${index}"]`);
      if(!card) return;

      const vSel = card.querySelector('select[name="from"]');
      const yInp = card.querySelector('input[name="offsetY"]');
      const hSel = card.querySelector('select[name="place"]');
      const xInp = card.querySelector('input[name="offsetX"]');
      
      // เคลียร์ Error
      [yInp, xInp].forEach(el => { if(el) setFieldError(el, ''); });
      
      if(!yInp || !xInp || !vSel || !hSel) return;

      const {minYTop, minYBottom, minXLeft, minXRight} = minOffsetsFor(cfg);
      
      // ชื่อสินค้าสำหรับ Popup (แบบละเอียด)
      const variantStr = cfg.variant ? ` (${cfg.variant})` : '';
      const itemLabel = `${baseName}${variantStr} #${index + 1}`;

      // --- ตรวจสอบแกนตั้ง (Y) ---
      if (vSel.value !== 'center') {
          const yVal = +yInp.value || 0;
          const needY = (vSel.value === 'top') ? minYTop : minYBottom;
          
          if(yVal < needY){
            // [1] ข้อความสั้น (สำหรับ Panel)
            const shortMsg = `ต้องห่าง ${needY} cm ขึ้นไป`;

            // [2] ข้อความยาว (สำหรับ Popup)
            const dirTxt = (vSel.value === 'top') ? 'ขอบโต๊ะด้านบน' : 
                           (vSel.value === 'bottom') ? 'ขอบโต๊ะด้านล่าง' : 'จุดอ้างอิง';
            const longMsg = `${itemLabel} ต้องห่างจาก ${dirTxt} ${needY} cm ขึ้นไป`;
            
            // เรียก setFieldError(element, shortMsg, isMirror, longMsg)
            setFieldError(yInp, shortMsg, false, longMsg);
            
            ok = false;
            state.validation.ok = false;
            state.validation.messages.push({ field:`${key}[${index}].offsetY`, message:longMsg });
          }
      }

      // --- ตรวจสอบแกนนอน (X) ---
      if (hSel.value !== 'center') {
          const xVal = +xInp.value || 0;
          const needX = (hSel.value === 'left') ? minXLeft : minXRight;
          
          if(xVal < needX){
            // [1] ข้อความสั้น (สำหรับ Panel)
            const shortMsg = `ต้องห่าง ${needX} cm ขึ้นไป`;

            // [2] ข้อความยาว (สำหรับ Popup)
            const dirTxt = (hSel.value === 'left') ? 'ขอบโต๊ะด้านซ้าย' : 
                           (hSel.value === 'right') ? 'ขอบโต๊ะด้านขวา' : 'จุดอ้างอิง';
            const longMsg = `${itemLabel} ต้องห่างจาก ${dirTxt} ${needX} cm ขึ้นไป`;

            // เรียก setFieldError(element, shortMsg, isMirror, longMsg)
            setFieldError(xInp, shortMsg, false, longMsg);

            ok = false;
            state.validation.ok = false;
            state.validation.messages.push({ field:`${key}[${index}].offsetX`, message:longMsg });
          }
      }
    });
  });
  
  return ok;
}

    function persistMetaCache(meta){
      if(typeof window === 'undefined' || !window.localStorage) return;
      try {
        const payload = { savedAt: Date.now(), data: meta };
        localStorage.setItem(META_CACHE_KEY, JSON.stringify(payload));
      } catch(err){}
    }

    function readMetaCache(){
      if(typeof window === 'undefined' || !window.localStorage) return null;
      try {
        const raw = localStorage.getItem(META_CACHE_KEY);
        if(!raw) return null;
        const parsed = JSON.parse(raw);
        if(parsed && parsed.data && typeof parsed.data === 'object'){
          return parsed.data;
        }
      } catch(err){}
      return null;
    }

    function showStatusMessage(text, color){
      const el = byId('dpb-msg');
      if(!el) return;
      if(!text){
        el.textContent='';
        el.removeAttribute('style');
        return;
      }
      el.textContent = text;
      el.style.color = color || '#6b7280';
    }

function parseNumberList(input){
  if (input == null) return [];
  if (Array.isArray(input)) {
    return input
      .map(n => (typeof n === 'string' ? parseFloat(n.trim()) : Number(n)))
      .filter(n => Number.isFinite(n));
  }
  if (typeof input === 'number') return Number.isFinite(input) ? [input] : [];
  if (typeof input === 'string') {
    const s = input.trim();
    if (!s) return [];
    return s.split(',').map(t => parseFloat(t.trim())).filter(Number.isFinite);
  }
  return [];
}

function minOffsetsFor(cfg){
  const pos = (cfg.pos || 'main').toLowerCase();
  const isRotated = !!cfg.rotate; 
  const typeEl  = document.getElementById('dpb-type');
  const asideEl = document.getElementById('dpb-aside');
  const type  = (typeEl ? typeEl.value : '').toLowerCase();
  const aside = (asideEl ? asideEl.value : 'right').toLowerCase();
  let minYTop = 5, minYBottom = 5;
  let minXLeft = 10, minXRight = 10;
  if (pos === 'main') {
    if (isRotated) {
       minXLeft = 5;
       minXRight = 5;
    } else {
       if (type === 'l3') {
           if (aside === 'right') {
               minXRight = 5;
               minXLeft  = 10;
           } else {
               minXLeft  = 5;
               minXRight = 10;
           }
       } else {
           minXLeft = 10;
           minXRight = 10;
       }
    }
    return { minYTop, minYBottom, minXLeft, minXRight };
  }
  if (pos === 'left' || pos === 'right' || pos === 'arm') {
     minYBottom = 5; 
     minYTop    = 5;
     minXLeft   = 5;
     minXRight  = 5;
  }
  return { minYTop, minYBottom, minXLeft, minXRight };
}

function enrichOptionVariantDefaults(){
  const opts = (state?.meta?.options || []);
  opts.forEach(opt=>{
    const vList = Array.isArray(opt.variants) ? opt.variants : [];
    if(!vList.length) return;
    const wArr = parseNumberList(opt.defaultWcm);
    const hArr = parseNumberList(opt.defaultHcm);
    vList.forEach((v, i)=>{
      if(!Number.isFinite(v.defaultWcm) && Number.isFinite(wArr[i])) v.defaultWcm = wArr[i];
      if(!Number.isFinite(v.defaultHcm) && Number.isFinite(hArr[i])) v.defaultHcm = hArr[i];
    });
  });
}


    function hidePreloadNow(){
      try{
        const preload = document.getElementById('preload');
        if(!preload) return;
        preload.classList.add('is-ready');
        setTimeout(()=>{ preload.style.display = 'none'; }, 350);
      }catch(_){}
    }

async function loadMeta(){
  const endpoint = `${AJAX_URL}?action=dpb_meta&ts=${Date.now()}`;
  let metaData = null;
  let usedCache = false;
  try {
    const res = await fetch(endpoint, { credentials:'same-origin', cache:'no-store' });
    let payload = null;
    try { payload = await res.json(); } catch(parseErr) { payload = null; }
    if(!res.ok){
      const errMsg = payload && payload.data && payload.data.message ? payload.data.message : `HTTP ${res.status}`;
      throw new Error(errMsg);
    }
    if(payload && typeof payload.success === 'boolean'){
      if(payload.success){
        metaData = payload.data;
      } else {
        const errMsg = payload.data && payload.data.message ? payload.data.message : 'ไม่สามารถโหลดข้อมูลจากเซิร์ฟเวอร์';
        throw new Error(errMsg);
      }
    } else {
      metaData = payload;
    }
    if(!metaData || typeof metaData !== 'object'){
      throw new Error('รูปแบบข้อมูลไม่ถูกต้อง');
    }
    persistMetaCache(metaData);
  } catch(err){
    const cached = readMetaCache();
    if(cached){
      metaData = cached;
      usedCache = true;
      console.warn('[DPB] meta fallback to cache:', err);
    } else {
      console.error('[DPB] meta load failed:', err);
      throw err;
    }
  }
  const normalised = {
    colors:  Array.isArray(metaData?.colors)  ? metaData.colors  : [],
    legs:    Array.isArray(metaData?.legs)    ? metaData.legs    : [],
    options: Array.isArray(metaData?.options) ? metaData.options : [],
    models:  Array.isArray(metaData?.models)  ? metaData.models  : [],
    edges:   Array.isArray(metaData?.edges)   ? metaData.edges   : [],
  };
  state.meta = normalised;
  enrichOptionVariantDefaults?.();
  fillSelects?.();
  if (typeof buildTopColorTilesGroupedSafe === 'function') {
    buildTopColorTilesGroupedSafe();
  }
  if (typeof initEdgeAndLegTiles === 'function') {
    try { initEdgeAndLegTiles(); } catch(e){ console.warn('[DPB] initEdgeAndLegTiles threw:', e); }
  }
  try { rebuildLegTilesFromSheet(); } catch(e){ console.warn('[DPB] rebuildLegTilesFromSheet failed:', e); }
  buildOptions?.();
  buildOptConfig?.();
  bindCartEvents?.();
  syncRBlocks?.(true);
  setTimeout(()=>{
    try { hidePreloadNow(); } catch(_){}
    try { drawFooter(); }catch(_){}
    try { measureInfoGrid(); }catch(_){}
    scheduleRedraw?.();
  }, 100);
  console.log('[DPB] meta loaded', {
    colors: state.meta.colors?.length||0,
    legs:   state.meta.legs?.length||0,
    edges:  state.meta.edges?.length||0,
    options:state.meta.options?.length||0,
    cache:  usedCache
  });
  return { usedCache };
}


function fillSelects() {
    const selColor = byId('dpb-top-color');
    selColor.innerHTML = '';
    const tileWrap = byId('dpb-top-color-tiles');
    if (tileWrap) tileWrap.innerHTML = '';
    
    // เตรียมตัวแปร cache สำหรับรูป 3D (ถ้ายังไม่มีให้สร้าง object ว่างรอไว้)
    state.colorImg3DCache = state.colorImg3DCache || {}; 

    const colors = (state.meta.colors || []);
    colors.forEach(c => {
        const o = document.createElement('option');
        o.value = c.key;
        o.textContent = `${c.name} (${c.key})`;
        selColor.appendChild(o);

        // 1. โหลดรูป 2D (ของเดิม)
        if (c.imageUrl) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.src = c.imageUrl;
            img.onload = () => {
                state.colorImgCache[c.key] = img;
                scheduleRedraw();
            };
            state.colorImgCache[c.key] = img;
        }

        // ============================================================
        // [NEW] 2. โหลดรูป 3D (ส่วนที่เพิ่มใหม่)
        // เช็คว่ามีลิ้งก์ imagetop3d จาก API หรือไม่
        // ============================================================
        if (c.imagetop3d) {
            const img3d = new Image();
            img3d.crossOrigin = 'anonymous';
            img3d.src = c.imagetop3d;
            // เมื่อโหลดเสร็จ ให้สั่งวาดใหม่เหมือนกัน
            img3d.onload = () => {
                state.colorImg3DCache[c.key] = img3d; 
                scheduleRedraw();
            };
            // เก็บลง Cache ตัวใหม่ ชื่อ state.colorImg3DCache
            state.colorImg3DCache[c.key] = img3d;
        }
    });

    if (selColor.options.length > 0 && !selColor.value) {
        selColor.value = selColor.options[0].value;
    }

    const selLegs = byId('dpb-legs');
    if (selLegs) {
        selLegs.innerHTML = '';
        (state.meta.legs || []).forEach(v => {
            const o = document.createElement('option');
            o.value = v.key;
            o.textContent = v.name;
            selLegs.appendChild(o);
            if (v.imageUrl) {
                state.legImgCache = state.legImgCache || {};
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.src = v.imageUrl;
                img.onload = () => { state.legImgCache[v.key] = img; };
                state.legImgCache[v.key] = img;
            }
        });
        if (selLegs.options.length > 0 && !selLegs.value) {
            selLegs.value = selLegs.options[0].value;
        }
    }
}

function rebuildLegSelectFromSheet(){
  const legsSel = document.getElementById('dpb-legs');
  if (!legsSel) return;
  const raw = Array.isArray(state?.meta?.legs) ? state.meta.legs : [];
  const isL = isLDeskType();
  let filtered = raw.filter(x => x && String(x.key||'').trim() !== '');
  if (isL){
    filtered = filtered.filter(x => isLegAllowedForLDesk(x.key, x.name));
  }
  const prevKeys = Array.from(legsSel.options).map(o=>o.value);
  const newKeys  = filtered.map(i=>String(i.key));
  const needRebuild = (prevKeys.length !== newKeys.length) ||
                      prevKeys.some((k,idx)=>k!==newKeys[idx]);
  if (needRebuild){
    const current = legsSel.value;
    legsSel.innerHTML = '';
    filtered.forEach(it=>{
      const opt = document.createElement('option');
      opt.value = String(it.key);
      opt.text  = String(it.name || it.key);
      legsSel.appendChild(opt);
    });
    let next = current;
    if (!filtered.some(i=>String(i.key)===current)){
      next = filtered.find(i=>i.key==='square-white')?.key
          || filtered.find(i=>i.key==='square-black')?.key
          || (filtered[0]?.key || '');
    }
    if (next) legsSel.value = next;
  }else{
    const val = legsSel.value;
    if (isL && !filtered.some(i=>i.key===val)){
      const fallback = filtered.find(i=>i.key==='square-white')?.key
                    || filtered.find(i=>i.key==='square-black')?.key
                    || (filtered[0]?.key || '');
      if (fallback) legsSel.value = fallback;
    }
  }
}

function rebuildLegTilesFromSheet(){
  const legsSel  = document.getElementById('dpb-legs');
  const legsHost = document.getElementById('dpb-legs-tiles');
  if (!legsSel || !legsHost) return;
  const deskType = getDeskType();
  const rawLegs = Array.isArray(state?.meta?.legs) ? state.meta.legs : [];
  const filteredLegs = rawLegs.filter(row => {
    if (!row) return false;
    if (!String(row.key||'').trim()) return false;
    return isLegAllowedForType(row, deskType);
  });
  const prevVal = legsSel.value;
  let needBuildSelect = false;
  if (legsSel.options.length !== filteredLegs.length) {
    needBuildSelect = true;
  } else {
    const selKeys  = Array.from(legsSel.options).map(o=>o.value);
    const listKeys = filteredLegs.map(i=>String(i.key));
    needBuildSelect = selKeys.some((k,idx)=>k!==listKeys[idx]);
  }
  if (needBuildSelect){
    legsSel.innerHTML = '';
    filteredLegs.forEach(item=>{
      const opt = document.createElement('option');
      opt.value = String(item.key);
      opt.text  = String(item.name || item.key);
      legsSel.appendChild(opt);
    });
  }
  const coerced = coerceLegSelectionToAllowed(prevVal || legsSel.value, filteredLegs, deskType);
  if (legsSel.value !== coerced) {
    legsSel.value = coerced;
    try{ drawFooter(); }catch(_){}
    try{ measureInfoGrid(); }catch(_){}
    legsSel.dispatchEvent(new Event('change', { bubbles:true }));
    if (typeof scheduleRedraw === 'function') scheduleRedraw();
  }
  legsHost.innerHTML = '';
  legsHost.classList.add('dpb-type-tiles');
  legsHost.setAttribute('aria-label', 'เลือกโครงขา');
  const selectedVal = legsSel.value;
  const onPick = (val)=>{
    if (legsSel.value !== val){
      legsSel.value = val;
      try{ drawFooter(); }catch(_){}
      try{ measureInfoGrid(); }catch(_){}
      legsSel.dispatchEvent(new Event('change', { bubbles:true }));
      if (typeof scheduleRedraw==='function') scheduleRedraw();
    }
  };
  filteredLegs.forEach(item=>{
    const value = String(item.key);
    const label = String(item.name || item.key);
    const img   = String(item.imageUrl || '');
    const isActive = (value === selectedVal);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dpb-type-card';
    btn.setAttribute('data-value', value);
    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    btn.setAttribute('tabindex', isActive ? '0' : '-1');
    btn.innerHTML = `
      <span class="dpb-type-card__chip">
        ${img
          ? `<img decoding="async" loading="lazy" alt="${escapeHtml(label)}" src="${escapeAttr(img)}">`
          : `<span class="dpb-type-card__chip--placeholder">No Image</span>`}
      </span>
      <span class="dpb-type-card__name">${escapeHtml(label)}</span>
    `;
    btn.addEventListener('click', ()=>{
      Array.from(legsHost.querySelectorAll('.dpb-type-card')).forEach(el=>{
        const on = el === btn;
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        el.setAttribute('tabindex', on ? '0' : '-1');
      });
      onPick(value);
      btn.focus();
    });
    btn.addEventListener('keydown', (ev)=>{
      const cards = Array.from(legsHost.querySelectorAll('.dpb-type-card'));
      const i = cards.indexOf(btn);
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault(); btn.click();
      } else if (ev.key === 'ArrowRight') {
        ev.preventDefault(); cards[(i+1)%cards.length]?.focus();
      } else if (ev.key === 'ArrowLeft') {
        ev.preventDefault(); cards[(i-1+cards.length)%cards.length]?.focus();
      }
    });
    legsHost.appendChild(btn);
  });
  if (!legsSel.__dpbLegTilesBound) {
    legsSel.addEventListener('change', ()=>{
      const val = legsSel.value;
      const cards = Array.from(legsHost.querySelectorAll('.dpb-type-card'));
      cards.forEach(el=>{
        const on = (el.getAttribute('data-value') === val);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        el.setAttribute('tabindex', on ? '0' : '-1');
      });
      try{ drawFooter(); }catch(_){}
      try{ measureInfoGrid(); }catch(_){}
      if (typeof scheduleRedraw==='function') scheduleRedraw();
    });
    legsSel.__dpbLegTilesBound = true;
  }
  if (window.DPB_DEBUG){
    console.log('[DPB][DBG] rebuildLegTilesFromSheet()', {
      deskType,
      total: rawLegs.length,
      allowed: filteredLegs.length,
      selected: legsSel.value
    });
  }
}

(function bindTypeWatcherForLegTiles(){
  const typeSel = document.getElementById('dpb-type');
  if (!typeSel) return;
  if (typeSel.__dpbLegFilterBound) return;
  typeSel.addEventListener('change', ()=>{
    rebuildLegTilesFromSheet();
  });
  typeSel.__dpbLegFilterBound = true;
})();

rebuildLegSelectFromSheet();
rebuildLegTilesFromSheet();

byId('dpb-type')?.addEventListener('change', ()=>{
  rebuildLegSelectFromSheet();
  rebuildLegTilesFromSheet();
  if (typeof scheduleRedraw==='function') scheduleRedraw();
});

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, m => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[m]));
}

function escapeAttr(s){
  return String(s).replace(/["'<>\s]/g, c=>({'"':'&quot;', "'":'&#39;', '<':'%3C', '>':'%3E', ' ':'%20'}[c]||c));
}

function getVariantDefaults(optKey, variantName){
  try{
    const opt = (state?.meta?.options||[]).find(o=>o.key===optKey);
    if(!opt) return null;
    const baseW = Number(opt.defaultWcm||0);
    const baseH = Number(opt.defaultHcm||0);
    if(!variantName){
      return { wcm: baseW, hcm: baseH, source: 'option' };
    }
    const vList = Array.isArray(opt.variants)? opt.variants : [];
    const v = vList.find(v=>String(v.name).trim() === String(variantName).trim());
    if(v && (Number.isFinite(v.defaultWcm) || Number.isFinite(v.defaultHcm))){
      const w = Number.isFinite(v.defaultWcm) ? v.defaultWcm : baseW;
      const h = Number.isFinite(v.defaultHcm) ? v.defaultHcm : baseH;
      return { wcm: w, hcm: h, source: 'variant' };
    }
    return { wcm: baseW, hcm: baseH, source: 'option' };
  }catch(_){
    return null;
  }
}

function applyVariantToOptConfig(optKey, cartItemEl, wcm, hcm){
  if(!state || !state.optConfig) return;
  const bucket = state.optConfig[optKey];
  if(!Array.isArray(bucket) || bucket.length===0) return;
  let foundIdx = -1;
  const uidFromDom = cartItemEl?.dataset?.uid || '';
  if(uidFromDom){
    foundIdx = bucket.findIndex(it => String(it?.uid||'') === String(uidFromDom));
  }
  if(foundIdx < 0){
    const siblings = Array.from(cartItemEl?.parentElement?.querySelectorAll('.dpb-cart-item[data-opt-key="'+optKey+'"]') || []);
    const guessIdx = siblings.indexOf(cartItemEl);
    if(guessIdx >= 0 && guessIdx < bucket.length){
      foundIdx = guessIdx;
    }
  }
  if(foundIdx < 0) foundIdx = 0;
  const cfg = bucket[foundIdx];
  if(!cfg || typeof cfg !== 'object') return;
  const w_cm = Number.isFinite(+wcm) ? +wcm : undefined;
  const h_cm = Number.isFinite(+hcm) ? +hcm : undefined;
  const toMm = v => Number.isFinite(v) ? Math.round(v*10*1000)/1000 : undefined;
  if(Number.isFinite(w_cm)) cfg.wcm = w_cm;
  if(Number.isFinite(h_cm)) cfg.hcm = h_cm;
  const dia_cm = Number.isFinite(w_cm) ? w_cm : (Number.isFinite(h_cm) ? h_cm : undefined);
  const dia_mm = toMm(dia_cm);
  const rad_cm = Number.isFinite(dia_cm) ? (dia_cm/2) : undefined;
  const rad_mm = toMm(rad_cm);
  if(Number.isFinite(dia_cm)){
    cfg.diameterCm        = dia_cm;
    cfg.hole_diameter_cm = dia_cm;
    cfg.sizeCm            = dia_cm;
    cfg.d                 = dia_cm;
    cfg.radiusCm          = rad_cm;
    cfg.r                 = rad_cm;
  }
  if(Number.isFinite(dia_mm)){
    cfg.diameterMm        = dia_mm;
    cfg.hole_diameter_mm = dia_mm;
    cfg.d_mm              = dia_mm;
    cfg.size              = dia_mm;
  }
  if(Number.isFinite(rad_mm)){
    cfg.radiusMm          = rad_mm;
    cfg.r_mm              = rad_mm;
  }
  if(!cfg.type){
    if(/grommet|hole|วงกลม|กลม/i.test(optKey)) cfg.type = 'circle';
  }
  const activeSel = cartItemEl?.querySelector('select.dpb-variant-select, input.dpb-variant-radio:checked');
  if(activeSel) cfg.variantName = activeSel.value;
}

function applyVariantDefaultsToCard(optKey, card, variantName){
  if(!card) return;
  const index = Number(card.dataset.index);
  const cfg   = state.optConfig?.[optKey]?.[index];
  if(!cfg) return;
  const d = getVariantDefaults(optKey, variantName);
  if(!d) return;
  const wcm = Number.isFinite(+d.wcm) ? +d.wcm : 0;
  const hcm = Number.isFinite(+d.hcm) ? +d.hcm : 0;
  cfg.w = wcm;
  cfg.h = hcm;
  const inpW = card.querySelector('input[name="w"]');
  const inpH = card.querySelector('input[name="h"]');
  if(inpW) inpW.value = String(wcm);
  if(inpH) inpH.value = String(hcm);
  applyVariantToOptConfig(optKey, card, wcm, hcm);
}

function buildTopColorTilesGroupedSafe(){
    const wrap = document.getElementById('dpb-top-color-tiles');
    const sel  = document.getElementById('dpb-top-color');
    if(!wrap || !sel) return;
    
    wrap.innerHTML = '';
    const colors = Array.isArray(state?.meta?.colors) ? state.meta.colors : [];
    if(!colors.length) return;

    // 1. เพิ่ม key 'whiteboard' เข้าไปใน object เก็บกลุ่มข้อมูล
    const groups = { laminate: [], solid: [], solidwood: [], whiteboard: [] };

    colors.forEach(c=>{
        const g = String(c.group||'').trim().toLowerCase();
        // 2. เพิ่ม Logic การเช็ค group ที่เป็น 'whiteboard'
        if(g==='laminate') groups.laminate.push(c);
        else if(g==='solid') groups.solid.push(c);
        else if(g==='solidwood') groups.solidwood.push(c);
        else if(g==='whiteboard') groups.whiteboard.push(c);
    });

    // 3. เพิ่ม 'whiteboard' เข้าไปในลำดับการแสดงผล (Order)
    const order  = ['laminate','solid','solidwood', 'whiteboard'];

    // 4. กำหนดชื่อหัวข้อที่จะแสดงบนหน้าเว็บ
    const titles = {
        laminate:  'Particle+Laminate',
		whiteboard: 'Particle+Whiteboard',
        solid:     'Solid',
        solidwood: 'Solid Wood (ไม้แท้)'
        
    };

    order.forEach(key=>{
        const list = groups[key];
        if(!list?.length) return;

        const section = document.createElement('section');
        section.className = 'dpb-color-section';
        section.innerHTML = `
          <h4 class="dpb-color-section__title">${titles[key]}</h4>
          <div class="dpb-color-grid"></div>
        `;
        
        const grid = section.querySelector('.dpb-color-grid');
        
        list.forEach(c=>{
            // เช็คและเพิ่ม option ใน select หากยังไม่มี
            if(!Array.from(sel.options).some(o=>o.value===c.key)){
                const opt = document.createElement('option');
                opt.value = c.key;
                opt.textContent = `${c.name} (${c.key})`;
                sel.appendChild(opt);
            }

            const chip = c.iconUrl || c.imageUrl || '';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dpb-top-swatch';
            btn.dataset.key = c.key;
            // set aria-selected
            btn.setAttribute('aria-selected', (sel.value === c.key) ? 'true' : 'false');
            
            btn.innerHTML = `
              <div class="dpb-top-swatch__chip" style="${chip ? `background-image:url('${chip.replace(/"/g,'&quot;')}')` : ''}"></div>
              <div class="dpb-top-swatch__name">${c.name}</div>
            `;
            grid.appendChild(btn);
        });
        wrap.appendChild(section);
    });

    // Event Listener (ส่วนนี้เหมือนเดิม ไม่มีการแก้ไข Logic)
    if(!wrap.dataset.bound){
        wrap.addEventListener('click', (e)=>{
            const btn = e.target.closest('.dpb-top-swatch');
            if(!btn || !wrap.contains(btn)) return;
            
            const key = btn.dataset.key;
            if(!key) return;

            if(!Array.from(sel.options).some(o=>o.value===key)){
                const found = colors.find(c => c.key === key);
                const opt = document.createElement('option');
                opt.value = key;
                opt.textContent = found ? `${found.name} (${found.key})` : key;
                sel.appendChild(opt);
            }

            sel.value = key;
            sel.dispatchEvent(new Event('input',  { bubbles:true }));
            sel.dispatchEvent(new Event('change', { bubbles:true }));

            wrap.querySelectorAll('.dpb-top-swatch').forEach(el=>{
                el.setAttribute('aria-selected', el.dataset.key===key ? 'true' : 'false');
            });
            
            if(typeof scheduleRedraw === 'function') scheduleRedraw();
        });
        wrap.dataset.bound = '1';
    }
    
    // Update active state based on current select value
    wrap.querySelectorAll('.dpb-top-swatch').forEach(el=>{
        el.setAttribute('aria-selected', el.dataset.key===sel.value ? 'true' : 'false');
    });
}

    function changeCount(key, delta){
      const item = state.selectedOptions[key] || {count:0};
      const after = Math.max(0, (item.count||0) + delta);
      item.count = after; state.selectedOptions[key]=item;
      ensureOptConfig(key);
      const arr = state.optConfig[key];
      while(arr.length < after){ arr.push(defaultCfgFor(key)); }
      while(arr.length > after){ arr.pop(); }
      if(after === 0){ delete state.uiExpanded[key]; }
      updateCartBadge();
    }

function toggleLDeskExtra(){
  const type = byId('dpb-type').value;
  if(type === 'l2' || type === 'l3'){
    byId('dpb-ldesk-extra').style.display = '';
  }else{
    byId('dpb-ldesk-extra').style.display = 'none';
  }
}

byId('dpb-type').addEventListener('change', toggleLDeskExtra);

toggleLDeskExtra();

    function reflectCard(card){
      const k = card.dataset.key;
      const count = state.selectedOptions[k]?.count || 0;
      const countEl = card.querySelector('.dpb-opt-count');
      if(countEl) countEl.textContent = `${count} ชิ้น`;
      card.classList.toggle('active', count > 0);
    }

function toggleAside(){
  const aside = byId('dpb-aside').value;
  const lowerLeft   = byId('ld_r_step').closest('div');
  const lowerRight  = byId('ld_r_br').closest('div');
  const armLeft     = byId('ld_r_armbl').closest('div');
  const armRight    = byId('ld_r_armbr') ? byId('ld_r_armbr').closest('div') : null;
  if(aside === 'right'){
    lowerLeft.style.display  = '';
    lowerRight.style.display = 'none';
    armLeft.style.display    = '';
    if(armRight) armRight.style.display = '';
  }else{
    lowerLeft.style.display  = 'none';
    lowerRight.style.display = '';
    armLeft.style.display    = '';
    if(armRight) armRight.style.display = '';
  }
}

byId('dpb-aside').addEventListener('change', ()=>{
  toggleAside();
  buildOptConfig();
  refreshAllCartForms();
  scheduleRedraw();
});

toggleAside();

function resetLDeskOnAsideChange(){
  const type = byId('dpb-type').value;
  if (type !== 'l2' && type !== 'l3') return;
  [
    'ld_r_tl',
    'ld_r_tr',
    'ld_r_step',
    'ld_r_br',
    'ld_r_armbl',
    'ld_r_armbr'
  ].forEach(id=>{
    const el = byId(id);
    if (el) el.value = '50';
  });
  const inner = byId('dpb-rInner');
  if (inner) inner.value = '150';
  if (typeof validateInputs === 'function') validateInputs();
  if (typeof scheduleRedraw === 'function') scheduleRedraw();
}

    function updateOptCardCount(key){
      const card = document.querySelector(`.dpb-opt-item[data-key="${CSS.escape(key)}"]`);
      if(!card) return;
      const count = state.selectedOptions[key]?.count || 0;
      const countEl = card.querySelector('.dpb-opt-count');
      if(countEl) countEl.textContent = `${count} ชิ้น`;
      card.classList.toggle('active', count > 0);
    }

function refreshAllOptionCardCounters(){
  const wrap = byId('dpb-opt-list');
  if(!wrap) return;
  wrap.querySelectorAll('.dpb-opt-item').forEach(card=>{
    const key = card.dataset.key;
    const count = state.selectedOptions[key]?.count || 0;
    const countEl = card.querySelector('.dpb-opt-count');
    if(countEl) countEl.textContent = `${count} ชิ้น`;
    card.classList.toggle('active', count > 0);
  });
}

function scrollToTopSmooth(){
  try{
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }catch(_){
    window.scrollTo(0, 0);
  }
}

function bindCartEvents(){
  if(!cartBody){ console.error('[DPB] cartBody missing'); return; }
  if(cartBody.dataset.bound === '1') return;
  cartBody.dataset.bound = '1';
  cartBody.addEventListener('click', (e)=>{
    const rotateBtn = e.target.closest('.dpb-btn-rotate');
    if (rotateBtn) {
        e.preventDefault(); e.stopPropagation();
        const card = rotateBtn.closest('.dpb-cart-item');
        const key = card?.dataset.key;
        const index = Number(card?.dataset.index||0);
        const cfg = state.optConfig[key]?.[index];
        if(cfg) {
            cfg.rotate = !cfg.rotate;
            applyPlacementConstraints(card, cfg);
            if(typeof autoResetMinOffset==='function'){
                autoResetMinOffset(card, cfg, 'x');
                autoResetMinOffset(card, cfg, 'y');
            }
            buildOptConfig(); 
            scheduleRedraw();
        }
        return;
    }
    if(e.target.closest('.dpb-cart-remove')){
      const card = e.target.closest('.dpb-cart-item');
      const key = card.dataset.key;
      const index = Number(card.dataset.index);
      const op = (state.meta.options||[]).find(o=>o.key===key)||{};
      const grouped = card.dataset.grouped === '1';
      const variant = card.dataset.variant || '';
      showMiniRemoveConfirm({
        title: grouped ? `ลบ ${op.name||key} ทั้งหมด?` : `ลบรายการนี้?`,
        onYes: ()=>{
          if(grouped && String(op.type).toLowerCase()==='attach'){
             const bucket = state.optConfig[key]||[];
             let removed=0;
             for(let i=bucket.length-1;i>=0;i--){
               if(String(bucket[i]?.variant)===variant){ bucket.splice(i,1); removed++; }
             }
             const sel = state.selectedOptions[key];
             if(sel) sel.count = Math.max(0, (sel.count||0)-removed);
          }else{
             (state.optConfig[key]||=[]).splice(index,1);
             const sel = state.selectedOptions[key];
             if(sel) sel.count = Math.max(0,(sel.count||0)-1);
          }
          updateCartBadge?.(); buildOptConfig?.(); postProcessCartOrder?.(); scheduleRedraw?.();
        }
      });
      return;
    }
    const qtyBtn = e.target.closest('.dpb-qty__btn');
    if(qtyBtn){
       const card = qtyBtn.closest('.dpb-cart-item');
       const key = card.dataset.key;
       const index = Number(card.dataset.index);
       const action = qtyBtn.dataset.act;
       const op = (state.meta.options||[]).find(o=>o.key===key)||{};
       const grouped = card.dataset.grouped === '1';
       const variant = card.dataset.variant || '';
       const qtyInput = card.querySelector('input[type="number"]');
       const current = Number(qtyInput?.value || 1);
       if(action==='inc'){
          if(grouped) window.incAttachVariantCount(key, variant, op);
          else { 
             state.selectedOptions[key] = state.selectedOptions[key] || { count: 0 };
             state.optConfig[key] = state.optConfig[key] || [];
             state.selectedOptions[key].count += 1;
             state.optConfig[key].push(defaultCfgFor(key));
          }
       } else if(action==='dec'){
          if(grouped){
             if(current<=1) {  }
             else window.decAttachVariantCount(key, variant, op);
          } else {
             showMiniRemoveConfirm({ title:'ลบรายการนี้?', onYes:()=>{
                (state.optConfig[key]||=[]).splice(index,1);
                updateCartBadge?.(); buildOptConfig?.(); scheduleRedraw?.();
             }});
          }
       }
       updateCartBadge?.(); buildOptConfig?.(); scheduleRedraw?.();
    }
  }, true);
  cartBody.addEventListener('input', handleCartInput, true);
  cartBody.addEventListener('change', handleCartInput, true);
}

function ensureOptConfig(key){
  const op = (state.meta.options||[]).find(o=>o.key===key);
  if(!op) return;
  if(!state.optConfig[key]) state.optConfig[key]=[];
  if(state.optConfig[key].length===0) state.optConfig[key].push(defaultCfgFor(key));
}

function defaultCfgFor(key){
  const op = (state.meta.options||[]).find(o=>o.key===key) || {};
  return {
    type: op.type||'hole_rect',
    w: op.defaultWcm||0,
    h: op.defaultHcm||0,
    from:'top', offsetY:5,
    place:'left', offsetX:10,
    variant:(op.variants&&op.variants[0]) ? op.variants[0].name : ''
  };
}

function totalSelectedCount(){
  return Object.values(state.selectedOptions).reduce((sum,item)=>sum + (item?.count||0), 0);
}

function updateCartBadge(){
  const total = totalSelectedCount();
  const desktopBadge = document.getElementById('dpb-cart-count');
  const desktopBtn   = document.getElementById('dpb-cart-button');
  if (desktopBadge) {
      desktopBadge.textContent = total;
      desktopBadge.style.display = (total === 0) ? 'none' : 'flex';
  }
  if (desktopBtn) {
      desktopBtn.classList.toggle('is-empty', total === 0);
  }
  const footerBadge = document.getElementById('dpb-footer-count');
  if (footerBadge) {
      footerBadge.textContent = total;
      footerBadge.style.display = (total === 0) ? 'none' : 'flex';
  }
}

function normVariantName(v){ return String(v||'').trim().toLowerCase(); }

function findVariant(op, name){
  const n = normVariantName(name);
  return (op.variants||[]).find(v => normVariantName(v.name) === n) || null;
}

function getAttachVariantCount(optKey, variantName){
  const bucket = state.optConfig[optKey] || [];
  const N = v => String(v||'').trim().toLowerCase();
  const target = N(variantName);
  let count = 0;
  for (const cfg of bucket){
    if (N(cfg?.variant) === target) count++;
}
  return count;
}

function bindCartEventsOnce(){
  if (cartBody.dataset.boundEditToggle === '1') return;
  cartBody.dataset.boundEditToggle = '1';
  cartBody.addEventListener('click', (e)=>{
    const editBtn = e.target.closest('.dpb-cart-edit');
    if(!editBtn) return;
    const cardEl = editBtn.closest('.dpb-cart-item');
    if(!cardEl) return;
    const key   = cardEl.dataset.key;
    const index = Number(cardEl.dataset.index || 0);
    const ex = cardEl.classList.toggle('is-expanded');
    const form = cardEl.querySelector('.dpb-cart-form');
    if(form){
      form.style.removeProperty('display');
      form.hidden = !ex;
      if(ex){
        const placement = form.querySelector('[data-role="placement"]');
        if(placement){ placement.hidden = false; }
      }
    }
    (state.uiExpanded || (state.uiExpanded = {}));
    (state.uiExpanded[key] || (state.uiExpanded[key] = {}));
    state.uiExpanded[key][index] = ex;
  });
}

function buildOptions(){
  const wrap = document.getElementById('dpb-opt-list');
  if(!wrap){ 
    console.warn('[DPB] #dpb-opt-list not found; skip buildOptions()'); 
    return; 
  }
  wrap.innerHTML = '';
  const opts = (state.meta.options || []);
  opts.forEach(op=>{
    const firstImg = (String(op.imageUrl||'')
      .split(',')
      .map(s=>s.trim())
      .filter(Boolean)[0]) || '';
    const card = document.createElement('div');
    card.className = 'dpb-opt-item';
    card.dataset.key = op.key;
    const qty = state.selectedOptions[op.key]?.count || 0;
    card.innerHTML = `
      <div class="dpb-opt-imgwrap ratio-4-3">
        ${firstImg ? `<img src="${firstImg}" alt="">` : `<div class="noimg">ไม่มีรูป</div>`}
      </div>
      <div class="dpb-opt-name">${op.name}</div>
      <div class="dpb-opt-footer">
        <span class="dpb-opt-count">${qty} ชิ้น</span>
        <button type="button" class="dpb-opt-fab" aria-label="เพิ่ม ${op.name}" title="เพิ่ม ${op.name}">+</button>
      </div>
    `;
    state.selectedOptions[op.key] = state.selectedOptions[op.key] || { count: 0 };
    if(qty > 0) card.classList.add('active');
    wrap.appendChild(card);
  });
  if(!wrap.dataset.bound){
    wrap.dataset.bound = '1';
    wrap.addEventListener('click', e=>{
      const card = e.target.closest('.dpb-opt-item');
      if(!card || !wrap.contains(card)) return;
      const key = card.dataset.key;
      if(typeof window.openVariantModalForOption === 'function'){
          window.openVariantModalForOption(key, { cardEl: card });
      }
    }, true);
  }
}

function buildOptConfig(){
  cartBody.innerHTML='';
  const items = [];
  const ROTATE_SVG = `<svg class="rotate-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1959.64 2200"><path d="M407.19,356.87c15.92-24.37,33.42-47.49,52.36-69.2l14.63-15.88c1.23-1.3,2.39-2.68,3.69-3.92l3.84-3.78,7.67-7.55,7.66-7.52,8.02-7.16,8-7.15,3.99-3.57,4.16-3.38,16.61-13.46c5.7-4.28,11.48-8.44,17.19-12.66,5.62-4.33,11.7-8.06,17.57-12.02l8.85-5.87c1.45-1.01,2.99-1.89,4.51-2.79l4.56-2.71,9.11-5.42,4.54-2.71,2.28-1.35,2.34-1.24,18.66-9.93,2.33-1.24c.78-.4,1.59-.76,2.38-1.14l4.78-2.25,9.53-4.5,4.76-2.25c1.59-.74,3.15-1.53,4.79-2.17l9.72-4.03c12.82-5.63,26.11-10.09,39.2-14.81l19.98-6.1,4.98-1.53,5.06-1.28,10.09-2.56c26.91-6.64,54.13-11.61,81.51-13.88,54.66-5.14,109.51-1.67,162.2-9.64,52.77-11.13,103.3-30.23,149.21-56.09,45.96,25.83,87.45,58.43,122.85,96.09,27.55,29.28,51.41,61.62,71.1,95.97l-144.47,83.58,344.46,91.88,92.01-344.39-145.71,84.3c-33.14-45.65-71.75-86.87-114.64-122.42-51.44-42.69-109.03-77.21-169.93-102.15-60.91-25.01-125-40.37-189.34-45.91-64.37-5.74-129.19-1.7-191.34,11.93-31.14,6.52-61.5,15.98-90.94,27.2l-11.01,4.28-5.49,2.14-5.4,2.4-21.52,9.59c-14.04,7.05-28.14,13.84-41.61,21.81l-10.18,5.77c-1.71.93-3.34,2.01-5,3.04l-4.95,3.12-9.88,6.24-4.93,3.12c-.82.53-1.65,1.02-2.45,1.57l-2.39,1.67-19.09,13.37-2.38,1.67-2.31,1.77-4.61,3.56-9.19,7.12-4.59,3.56c-1.53,1.19-3.07,2.35-4.52,3.63l-8.83,7.53c-5.84,5.06-11.85,9.89-17.38,15.3-5.61,5.3-11.26,10.53-16.8,15.86l-16.03,16.59-3.98,4.15-3.8,4.33-7.58,8.65-7.56,8.63-7.16,8.97-7.13,8.97-3.56,4.48c-1.2,1.48-2.26,3.08-3.39,4.62l-13.33,18.57c-17.1,25.21-32.33,51.49-45.64,78.57-12.96,27.28-23.86,55.33-32.85,83.84-8.65,28.65-15.05,57.77-19.72,87.02,8.19-28.5,18.01-56.36,29.84-83.24,12.17-26.74,26.03-52.52,41.6-77.09Z"/><path d="M1552.45,1843.13c-15.92,24.37-33.42,47.49-52.36,69.2l-14.63,15.88c-1.23,1.3-2.39,2.68-3.69,3.92l-3.84,3.78-7.67,7.55-7.66,7.52-8.02,7.16-8,7.15-3.99,3.57-4.16,3.38-16.61,13.46c-5.7,4.28-11.48,8.44-17.19,12.66-5.62,4.33-11.7,8.06-17.57,12.02l-8.85,5.87c-1.45,1.01-2.99,1.89-4.51,2.79l-4.56,2.71-9.11,5.42-4.54,2.71-2.28,1.35-2.34,1.24-18.66,9.93-2.33,1.24c-.78.4-1.59.76-2.38,1.14l-4.78,2.25-9.53,4.5-4.76,2.25c-1.59.74-3.15,1.53-4.79,2.17l-9.72,4.03c-12.82,5.63-26.11,10.09-39.2,14.81l-19.98,6.1-4.98,1.53-5.06,1.28-10.09,2.56c-26.91,6.64-54.13,11.61-81.51,13.88-54.66,5.14-109.51,1.67-162.2-9.64-52.77-11.13-103.3-30.23-149.21-56.09-45.97-25.83-87.45-58.43-122.85-96.09-27.55-29.28-51.41-61.62-71.1-95.97l144.47-83.58-344.46-91.88-92.01,344.39,145.71-84.3c33.14,45.65,71.75,86.87,114.64,122.42,51.44,42.69,109.03,77.21,169.93,102.15,60.91,25.01,124.99,40.37,189.34,45.91,64.37,5.74,129.19,1.7,191.34-11.93,31.14-6.52,61.5-15.98,90.94-27.2l11.01-4.28,5.49-2.14,5.4-2.4,21.52-9.59c14.04-7.05,28.14-13.84,41.61-21.81l10.18-5.77c1.71-.93,3.34-2.01,5-3.04l4.95-3.12,9.88-6.24,4.93-3.12c.82-.53,1.65-1.02,2.45-1.57l2.39-1.67,19.09-13.37,2.38-1.67,2.31-1.77,4.61-3.56,9.19-7.12,4.59-3.56c1.53-1.19,3.07-2.35,4.52-3.63l8.83-7.53c5.84-5.06,11.85-9.89,17.38-15.3,5.61-5.3,11.26-10.53,16.8-15.86l16.03-16.59,3.98-4.15,3.8-4.33,7.58-8.65,7.56-8.63,7.16-8.97,7.13-8.97-3.56,4.48c1.2-1.48,2.26-3.08,3.39-4.62l13.33-18.57c17.1-25.21,32.33-51.49,45.64-78.57,12.96-27.28,23.86-55.33,32.85-83.84,8.65-28.65,15.05-57.77,19.72-87.02-8.19-28.5-18.01-56.36,29.84-83.24-12.17-26.74-26.03-52.52-41.6,77.09Z"/><path d="M52.16,643.64h1855.33c28.79,0,52.16,23.37,52.16,52.16v808.4c0,28.79-23.37,52.16-52.16,52.16H52.16c-28.79,0-52.16-23.37-52.16-52.16v-808.4c0-28.79,23.37-52.16,52.16-52.16Z"/></svg>`;
  Object.keys(state.selectedOptions).forEach(key=>{
    const sel = state.selectedOptions[key];
    if(!sel || sel.count<=0) return;
    const op  = (state.meta.options||[]).find(o=>o.key===key) || {};
    const arr = state.optConfig[key] || [];
    for(let i=0;i<sel.count;i++){
      const cfg = arr[i] || defaultCfgFor(key);
      items.push({ key, index:i, op, cfg });
    }
  });
  if(items.length === 0){
    cartEmpty.style.display='block';
    cartBody.style.display='none';
    return;
  }
  cartEmpty.style.display='none';
  cartBody.style.display='flex';
  const nonAttach = [];
  const attachMap = new Map();
  items.forEach(item=>{
    const { key, op, cfg } = item;
    const isAttach = String(op.type||'').toLowerCase()==='attach';
    const variant  = String(cfg.variant||'').trim();
    if(isAttach){
      const gKey = `${key}::${variant}`;
      if(!attachMap.has(gKey)) attachMap.set(gKey, { key, op, variant, items:[item] });
      else attachMap.get(gKey).items.push(item);
    }else{
      nonAttach.push(item);
    }
  });
  attachMap.forEach(entry=>{
    const { key, op, variant } = entry;
    const vImg = findVariant(op, variant)?.imageUrl || (String(op.imageUrl||'').split(',').map(s=>s.trim()).filter(Boolean)[0]) || '';
    const countNow = getAttachVariantCount(key, variant);
    const card = document.createElement('div');
    card.className = 'dpb-cart-item is-expanded';
    card.dataset.key = key;
    card.dataset.index = '0'; 
    card.dataset.variant = variant;
    card.dataset.grouped = '1';
    card.innerHTML = `
      <div class="dpb-cart-item-header">
        <div class="dpb-cart-thumb">${vImg ? `<img src="${vImg}" alt="">` : '<span>ไม่มีรูป</span>'}</div>
        <div class="dpb-cart-mid">
          <div class="dpb-cart-name">${op.name || key}${(Array.isArray(op.variants)&&op.variants.length>0 && variant) ? ` (${variant})` : ''}</div>
          <div class="dpb-cart-actions">
            <button type="button" class="dpb-cart-edit" disabled style="opacity:0;cursor:not-allowed; display: none;">แก้ไขข้อมูล</button>
            <div class="dpb-qty-mini">
              <button type="button" class="dpb-qty__btn" data-act="dec">−</button>
              <input type="number" value="${countNow}" min="0" inputmode="numeric">
              <button type="button" class="dpb-qty__btn" data-act="inc">+</button>
            </div>
          </div>
        </div>
        <button type="button" class="dpb-cart-remove">${TRASH_SVG}</button>
      </div>
      <div class="dpb-cart-form"></div>`;
    cartBody.appendChild(card);

  });
  nonAttach.forEach(item=>{
    const {key,index,op,cfg} = item;
    const firstImg = (()=>{
      if(cfg.variant){ const match = findVariant(op, cfg.variant); if(match && match.imageUrl) return match.imageUrl; }
      return (String(op.imageUrl||'').split(',').map(s=>s.trim()).filter(Boolean)[0]) || '';
    })();
    const hasVariant = (op.variants && op.variants.length > 0);
    const variantLabel = hasVariant ? (cfg.variant ? ` (${cfg.variant})` : '') : '';
    const showPlacement = true; 
    const card = document.createElement('div');
    card.className = 'dpb-cart-item';
    card.dataset.key = key;
    card.dataset.index = index;
    const placementForm = showPlacement ? `
      <div class="dpb-cart-placement" data-role="placement">
        <div class="dpb-form-row">
          <div>
            <label data-role="label-v">การจัดวางแนวตั้ง</label>
            <select name="from" data-key="${key}" data-index="${index}"></select>
          </div>
          <div>
            <label data-role="label-vlen">ระยะห่าง (cm)</label>
            <input name="offsetY" type="number" step="0.1" value="${Number.isFinite(cfg.offsetY)? cfg.offsetY : 5}" data-key="${key}" data-index="${index}">
          </div>
        </div>
        <div class="dpb-form-row">
          <div>
            <label data-role="label-h">การจัดวางแนวนอน</label>
            <select name="place" data-key="${key}" data-index="${index}"></select>
          </div>
          <div>
            <label data-role="label-hlen">ระยะห่าง (cm)</label>
            <input name="offsetX" type="number" step="0.1" value="${(String(cfg.place).toLowerCase()==='center')? '' : (Number.isFinite(cfg.offsetX)? cfg.offsetX : 10)}" data-key="${key}" data-index="${index}">
          </div>
        </div>
      </div>` : ``;
    const isRotated = !!cfg.rotate;
    const rotateBtnClass = isRotated ? 'dpb-btn-rotate is-active' : 'dpb-btn-rotate';
    const rotateIconClass = isRotated ? 'rotate-icon is-rotated' : 'rotate-icon'; 
    const rotateTitle = isRotated ? 'กลับไปวางท็อปหลัก' : 'หมุนไปวางด้านข้าง';
    const rotateLabel = isRotated ? 'แนวตั้ง' : 'แนวนอน'; 
    const rotateSvgHtml = ROTATE_SVG.replace('class="rotate-icon"', `class="${rotateIconClass}"`);
    const btnHtml = `
       <button type="button" class="${rotateBtnClass}" title="${rotateTitle}" data-act="rotate" style="width:100%;">
          ${rotateSvgHtml} ${rotateLabel}
       </button>
    `;
    const topRow = `
      <div class="dpb-form-row dpb-form-row--2">
        <input type="hidden" name="pos" value="${cfg.pos || 'main'}" data-key="${key}" data-index="${index}">
        ${hasVariant ? `
        <div>
          <label>ตัวเลือก</label>
          <select name="variant" data-key="${key}" data-index="${index}" style="width:100%;">
             ${(op.variants||[]).map(v=>`<option value="${v.name}">${v.name}</option>`).join('')}
          </select>
        </div>
        <div>
           <label>หมุนทิศทาง</label>
           ${btnHtml}
        </div>
        ` : `
        <div style="grid-column: span 2;">
           <label>หมุนทิศทาง</label>
           ${btnHtml}
        </div>
        `}
      </div>
    `;
// --- แก้ไข Logic Admin และ Toggle ใหม่ ---
        const isAdmin = window.wpData && window.wpData.isAdmin;
        let hideDimToggle = '';
        if (isAdmin) {
            const isHideDim = !!cfg.hideDim;
            hideDimToggle = `
            <div class="dpb-hide-dim-wrap" style="margin-top: 8px; display: flex; align-items: center; gap: 6px;">
                <input type="checkbox" name="hideDim" id="hideDim-${key}-${index}" 
                       data-key="${key}" data-index="${index}" ${isHideDim ? 'checked' : ''} 
                       style="width: 16px; height: 16px; cursor: pointer;">
                <label for="hideDim-${key}-${index}" style=" font-size: 10px; cursor: pointer; color: #d63031; line-height: 1; margin-bottom: 0px; font-weight: 300;">
                    ซ่อนเส้น
                </label>
            </div>`;
        }

        card.innerHTML = `
      <div class="dpb-cart-item-header">
        <div class="dpb-cart-thumb">${firstImg ? `<img decoding="async" src="${firstImg}" alt="">` : '<span>ไม่มีรูป</span>'}</div>
        <div class="dpb-cart-mid">
          <div class="dpb-cart-name">${(op.name || key)}${variantLabel} #${index + 1}</div>
          <div class="dpb-cart-actions">
            ${(hasVariant || showPlacement) ? `<button type="button" class="dpb-cart-edit">แก้ไขข้อมูล</button>` : `<span class="dpb-cart-edit-placeholder"></span>`}
            ${hideDimToggle}
            <div class="dpb-qty-mini dpb-qty-mini--hidden"></div>
          </div>
        </div>
        <button type="button" class="dpb-cart-remove">${TRASH_SVG}</button>
      </div>
      ${(hasVariant || showPlacement) ? `<div class="dpb-cart-form">${topRow}${placementForm}</div>` : ``}
    `;

        const variantSel = card.querySelector('select[name="variant"]');
        if (variantSel) variantSel.value = String(cfg.variant || (op.variants?.[0]?.name || '')).trim();
        if (showPlacement) applyPlacementConstraints(card, cfg);

        const expanded = !!(state.uiExpanded?.[key]?.[index]);
        const form = card.querySelector('.dpb-cart-form');
        if (expanded) {
            card.classList.add('is-expanded');
            if (form) { form.style.removeProperty('display'); form.hidden = false; }
        } else {
            if (form) form.style.display = 'none';
        }

        // Event: แก้ไขข้อมูล
        const btnEdit = card.querySelector('.dpb-cart-edit');
        if (btnEdit) {
            btnEdit.addEventListener('click', (ev) => {
                ev.preventDefault(); ev.stopPropagation();
                const willExpand = !card.classList.contains('is-expanded');
                card.classList.toggle('is-expanded', willExpand);
                const f = card.querySelector('.dpb-cart-form');
                if (f) f.style.removeProperty('display');
                if (willExpand) {
                    try {
                        const currCfg = (state.optConfig[key] || [])[index] || defaultCfgFor(key);
                        applyPlacementConstraints(card, currCfg);
                    } catch (e) { }
                }
                state.uiExpanded[key] = state.uiExpanded[key] || {};
                state.uiExpanded[key][index] = willExpand;
            }, true);
        }

        const hideDimCb = card.querySelector('input[name="hideDim"]');
        if (hideDimCb) {
            hideDimCb.addEventListener('change', (e) => {
                const k = e.target.dataset.key;
                const idx = parseInt(e.target.dataset.index);
                if (state.optConfig[k] && state.optConfig[k][idx]) {
                    state.optConfig[k][idx].hideDim = e.target.checked;
                    if (typeof scheduleRedraw === 'function') scheduleRedraw();
                }
            });
        }

        cartBody.appendChild(card);

['offsetY', 'offsetX'].forEach(fieldName => {
    const inp = card.querySelector(`input[name="${fieldName}"]`);
    if (!inp) return;
    inp.addEventListener('focus', () => {
        window._dpbOptFocus = { key, index, field: fieldName };
        startDimPulse('__opt__');
    });
    inp.addEventListener('blur', () => {
        window._dpbOptFocus = null;
        stopDimPulse();
    });
});
    });

    // ส่วนที่อยู่นอก Loop
    bindCartEventsOnce();
    refreshAllOptionCardCounters();
    if (typeof syncAllVariantDefaultsOnce === 'function') syncAllVariantDefaultsOnce();
    if (typeof scheduleRedraw === 'function') scheduleRedraw();
}

function syncAllVariantDefaultsOnce(){
  document.querySelectorAll('.dpb-cart-item').forEach(item=>{
    const optKey = item?.dataset?.key || '';
    const sel = item.querySelector('select[name="variant"]') 
              || item.querySelector('input.dpb-variant-radio:checked');
    const variantName = sel ? sel.value : '';
    if(!optKey) return;
    applyVariantDefaultsToCard(optKey, item, variantName);
  });
  if(typeof validateInputs==='function') validateInputs();
  if(typeof scheduleRedraw==='function') scheduleRedraw();
}

if (typeof window.incAttachVariantCount !== 'function'){
  window.incAttachVariantCount = function incAttachVariantCount(optKey, variantName, op){
    state.selectedOptions[optKey] = state.selectedOptions[optKey] || { count: 0 };
    state.selectedOptions[optKey].count += 1;
    state.optConfig[optKey] = state.optConfig[optKey] || [];
    state.optConfig[optKey].push({
      ...(typeof defaultCfgFor==='function' ? defaultCfgFor(optKey) : {}),
      type: String(op.type||'attach'),
      variant: Array.isArray(op.variants) && op.variants.length > 0 ? (variantName || '') : '',
      addedAt: Date.now()
    });
    updateCartBadge?.();
    buildOptConfig?.();
    postProcessCartOrder?.();
    scheduleRedraw?.();
  };
}

if (typeof window.decAttachVariantCount !== 'function'){
  window.decAttachVariantCount = function decAttachVariantCount(optKey, variantName, op){
    const bucket = state.optConfig[optKey] || [];
    const nvar = String(variantName||'').trim().toLowerCase();
    for(let i=bucket.length-1;i>=0;i--){
      const bvar = String(bucket[i]?.variant||'').trim().toLowerCase();
      if(bvar === nvar){
        bucket.splice(i,1);
        break;
      }
    }
    const sel = state.selectedOptions[optKey];
    if(sel){
      sel.count = Math.max(0, (sel.count||0)-1);
      if(sel.count===0){ delete state.optConfig[optKey]; delete state.uiExpanded[optKey]; }
    }
    updateCartBadge?.();
    buildOptConfig?.();
    postProcessCartOrder?.();
    scheduleRedraw?.();
  };
}

function moveAttachGroupVariant(optKey, oldVariant, newVariant, op){
  if(oldVariant === newVariant) return;
  const bucket = state.optConfig[optKey] || [];
  bucket.forEach(cfg=>{
    if(String(cfg?.variant||'') === String(oldVariant||'')){
      cfg.variant = newVariant;
    }
  });
  buildOptConfig();
  scheduleRedraw?.();
}

function handleCartClick(e){
  const card  = e.target.closest('.dpb-cart-item');
  if(!card) return;
  const key   = card.dataset.key;
  const index = Number(card.dataset.index || 0);
  const op    = (state.meta.options||[]).find(o=>o.key===key) || {};
  const grouped = card.dataset.grouped === '1';
  const hasVariants = Array.isArray(op.variants) && op.variants.length > 0;
  const variant = String(card.dataset.variant||'').trim();
  if(e.target.closest('.dpb-cart-remove')){
    showMiniRemoveConfirm({
      title: (()=> {
        if(grouped && String(op.type||'').toLowerCase()==='attach'){
          return hasVariants && variant
            ? `ลบ ${op.name || key} (${variant}) ทั้งหมดหรือไม่?`
            : `ลบ ${op.name || key} ทั้งหมดหรือไม่?`;
        }else{
          return hasVariants && variant
            ? `ลบ ${op.name || key} (${variant}) หรือไม่?`
            : `ลบ ${op.name || key} หรือไม่?`;
        }
      })(),
      onYes: ()=>{
        if(grouped && String(op.type||'').toLowerCase()==='attach'){
          const bucket = state.optConfig[key] || [];
          let removed = 0;
          for(let i=bucket.length-1;i>=0;i--){
            if(String(bucket[i]?.variant||'') === String(variant||'')){
              bucket.splice(i,1);
              removed++;
            }
          }
          const sel = state.selectedOptions[key];
          if(sel){
            sel.count = Math.max(0,(sel.count||0) - removed);
            if(sel.count===0){ delete state.optConfig[key]; delete state.uiExpanded[key]; }
          }
        }else{
          (state.optConfig[key] ||= []).splice(index,1);
          const sel = state.selectedOptions[key];
          if(sel){
            sel.count = Math.max(0,(sel.count||0)-1);
            if(sel.count===0){ delete state.optConfig[key]; delete state.uiExpanded[key]; }
          }
        }
        updateCartBadge?.();
        buildOptConfig?.();
        postProcessCartOrder?.();
        scheduleRedraw?.();
      },
      onNo: ()=>{}
    });
    return;
  }
}

function handleCartInput(e){
  const target = e.target;
  if(!target.dataset.key) {
    const card = target.closest('.dpb-cart-item');
    if(card && target.name === 'variant'){
       const key = card.dataset.key;
       const op  = (state.meta.options||[]).find(o=>o.key===key) || {};
       const isAttach = String(op.type||'').toLowerCase()==='attach';
       const grouped = card.dataset.grouped === '1';
       if(isAttach && grouped){
          const oldVariant = card.dataset.variant || '';
          const newVariant = target.value || '';
          if(oldVariant !== newVariant){
             if(typeof moveAttachGroupVariant === 'function'){
                moveAttachGroupVariant(key, oldVariant, newVariant, op);
             }
          }
          return;
       }
    }
    return;
  }
  const key = target.dataset.key;
  const index = Number(target.dataset.index);
  const cfg = state.optConfig[key]?.[index];
  if(!cfg) return;
  const op = (state.meta.options||[]).find(o=>o.key===key) || {};
  const card = cartBody.querySelector(`.dpb-cart-item[data-key="${CSS.escape(key)}"][data-index="${index}"]`);
  if(!card) return;
  switch(target.name){
    case 'w': 
        cfg.w = +target.value || 0; 
        break;
    case 'h': 
        cfg.h = +target.value || 0; 
        break;
    case 'offsetY': 
        cfg.offsetY = +target.value || 0; 
        break;
    case 'offsetX': 
        if(cfg.place !== 'center') cfg.offsetX = +target.value || 0; 
        break;
    case 'variant':
        cfg.variant = target.value;
        updateCartThumbVariant(card, op, cfg);
        if(typeof applyVariantDefaultsToCard === 'function'){
            applyVariantDefaultsToCard(key, card, cfg.variant);
        }
        applyPlacementConstraints(card, cfg);
        scheduleRedraw();
        break;
    case 'from':
        cfg.from = target.value;
        applyPlacementConstraints(card, cfg); 
        scheduleRedraw();
        break;
    case 'place':
        cfg.place = target.value;
        applyPlacementConstraints(card, cfg);
        scheduleRedraw();
        break;
    case 'pos':
        cfg.pos = target.value;
        applyPlacementConstraints(card, cfg);
        scheduleRedraw();
        break;
  }
  if(typeof syncCartItemState === 'function') syncCartItemState(card, cfg, op);
  if(typeof scheduleRedraw === 'function') scheduleRedraw();
}

function autoResetMinOffset(card, cfg, axis){
  const mins = (typeof minOffsetsFor === 'function') 
    ? minOffsetsFor(cfg) 
    : { minYTop:5, minYBottom:5, minXLeft:10, minXRight:10 };
  if(axis === 'y'){
    const f = String(cfg.from || '').toLowerCase();
    const isStart = (f === 'top' || f === 'left');
    const val = isStart ? mins.minYTop : mins.minYBottom;
    cfg.offsetY = val;
    const input = card.querySelector('input[name="offsetY"]');
    if(input) input.value = String(val);
  }
  if(axis === 'x'){
    const p = String(cfg.place || '').toLowerCase();
    if(p === 'center') return;
    const isStart = (p === 'left' || p === 'top');
    const val = isStart ? mins.minXLeft : mins.minXRight;
    cfg.offsetX = val;
    const input = card.querySelector('input[name="offsetX"]');
    if(input) input.value = String(val);
  }
}

function refreshAllCartPlacementForms(){
  const cards = cartBody.querySelectorAll('.dpb-cart-item');
  cards.forEach(card=>{
    const key = card.dataset.key;
    const index = Number(card.dataset.index);
    const cfg = state.optConfig[key]?.[index];
    if(!cfg) return;
    applyPlacementConstraints(card, cfg);
  });
}

function updateCartThumbVariant(card, op, cfg){
  const img = (()=>{
    if(cfg.variant){
      const match = findVariant(op, cfg.variant);
      if(match && match.imageUrl) return match.imageUrl;
    }
    return (String(op.imageUrl||'').split(',').map(s=>s.trim()).filter(Boolean)[0]) || '';
  })();
  const th = card.querySelector('.dpb-cart-thumb img');
  if(th && img){ th.src = img; }
}

function syncCartItemState(card, cfg, op){
  var placement = card.querySelector('[data-role="placement"]');
  var note = card.querySelector('[data-role="note"]');
  var ready = (typeof window.isPlacementReady === 'function')
    ? window.isPlacementReady(cfg, op)
    : true;
  if (placement) placement.hidden = !ready;
  if (note) note.style.display = ready ? 'none' : '';
}

    function rebuildOptionCardsAfterChange(key){
      if(!state.selectedOptions[key] || state.selectedOptions[key].count<=0){
        state.selectedOptions[key] = {count:0};
      }
      updateOptCardCount(key);
      updateCartBadge();
      buildOptConfig();
      postProcessCartOrder?.(); 
      scheduleRedraw();
    }

    function animateOptionToCart(card){
      const img = card.querySelector('.dpb-opt-imgwrap img');
      if(!img) return;
      const clone = img.cloneNode(true);
      const imgRect = img.getBoundingClientRect();
      const cartRect = cartButton.getBoundingClientRect();
      clone.style.position = 'fixed';
      clone.style.left = imgRect.left + 'px';
      clone.style.top = imgRect.top + 'px';
      clone.style.width = imgRect.width + 'px';
      clone.style.height = imgRect.height + 'px';
      clone.style.borderRadius = '12px';
      clone.style.zIndex = '10010';
      clone.style.pointerEvents = 'none';
      clone.style.transition = 'transform .55s ease, opacity .55s ease, left .55s ease, top .55s ease, width .55s ease, height .55s ease';
      document.body.appendChild(clone);
      requestAnimationFrame(()=>{
        const targetX = cartRect.left + cartRect.width/2 - imgRect.width*0.2;
        const targetY = cartRect.top + cartRect.height/2 - imgRect.height*0.2;
        clone.style.left = targetX + 'px';
        clone.style.top = targetY + 'px';
        clone.style.width = imgRect.width*0.4 + 'px';
        clone.style.height = imgRect.height*0.4 + 'px';
        clone.style.opacity = '0';
      });
      clone.addEventListener('transitionend', ()=> clone.remove(), {once:true});
    }

    const canvas = byId('dpb-canvas');
    const ctx = canvas.getContext('2d');

    function patFor(key){
      const img=state.colorImgCache[key];
      if(!img || !img.complete) return null;
      try{return ctx.createPattern(img,'repeat');}catch(e){return null;}
    }

    function drawImageCover(img, x, y, w, h){
      const ir = img.width / img.height;
      const r = w / h;
      let dw, dh;
      if(ir > r){ dh = h; dw = h * ir; }
      else { dw = w; dh = w / ir; }
      const dx = x + (w - dw)/2;
      const dy = y + (h - dh)/2;
      try{ ctx.drawImage(img, dx, dy, dw, dh); }catch(e){}
    }

    function endDot(x, y, color, r = 2.5){
      ctx.save();
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(x, y, r, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
    }

function deskScale(){
  const Lraw = +byId('dpb-ml')?.value || 0;
  const L    = Math.max(MIN_LEN_CM, Lraw);
  const sc   = FIXED_DRAW_LEN / L;
  return (isFinite(sc) && sc > 0) ? sc : 1;
}

function rectDeskHeight(){
  const sc  = deskScale();
  const Wcm = Math.max(MIN_W_CM, +byId('dpb-mw')?.value || 0);
  return Wcm * sc;
}

function ldeskHeight(){
  const sc   = deskScale();
  const AWcm = Math.max(MIN_AW_CM, +byId('dpb-aw')?.value || 0);
  return AWcm * sc;
}

   function getItems(){
  const itemsMap = new Map();
  Object.keys(state.selectedOptions).forEach(k=>{
    const sel = state.selectedOptions[k];
    if(!sel || sel.count<=0) return;
    const op = (state.meta.options||[]).find(o=>o.key===k) || {};
    const cfgArr = state.optConfig[k] || [];
    for(let i=0; i<sel.count; i++){
      const cfg = cfgArr[i] || defaultCfgFor(k);
      const variantNameRaw = (cfg.variant || '').trim();
      const hasVariants = Array.isArray(op.variants) && op.variants.length > 0;
      const variantName = hasVariants ? variantNameRaw : '';
      let img = '';
      if(variantName){
        const vv = (op.variants||[]).find(v=>v.name===variantName);
        img = vv?.imageUrl || '';
      }
      if(!img){
        img = (String(op.imageUrl||'').split(',').map(s=>s.trim()).filter(Boolean)[0]) || '';
      }
      const key2 = `${k}__${variantName || '_'}`;
      if(!itemsMap.has(key2)){
        itemsMap.set(key2, {
          name: op.name || k,
          img,
          detail: hasVariants ? variantName : '', 
          count: 0
        });
      }
      itemsMap.get(key2).count += 1;
    }
  });
  return Array.from(itemsMap.values());
}

function animateElementToCart(el){
  const cartBtn = document.getElementById('dpb-cart-button');
  if(!el || !cartBtn) return;
  const rect = el.getBoundingClientRect();
  const cartRect = cartBtn.getBoundingClientRect();
  const clone = el.cloneNode(true);
  if(clone.tagName.toLowerCase() === 'img'){
    clone.loading = 'eager';
    clone.decoding = 'sync';
  }
  clone.style.position = 'fixed';
  clone.style.left = rect.left + 'px';
  clone.style.top = rect.top + 'px';
  clone.style.width = rect.width + 'px';
  clone.style.height = rect.height + 'px';
  clone.style.borderRadius = '12px';
  clone.style.zIndex = '10020';
  clone.style.pointerEvents = 'none';
  clone.style.transition = 'transform .55s ease, opacity .55s ease, left .55s ease, top .55s ease, width .55s ease, height .55s ease';
  document.body.appendChild(clone);
  requestAnimationFrame(()=>{
    const targetX = cartRect.left + cartRect.width/2 - rect.width*0.2;
    const targetY = cartRect.top  + cartRect.height/2 - rect.height*0.2;
    clone.style.left = targetX + 'px';
    clone.style.top  = targetY + 'px';
    clone.style.width  = rect.width*0.4 + 'px';
    clone.style.height = rect.height*0.4 + 'px';
    clone.style.opacity = '0';
  });
  clone.addEventListener('transitionend', ()=> clone.remove(), {once:true});
}

function getPreferredVariantImageAndRect({ modal, cardEl } = {}){
  const headImg = modal?.querySelector('.dpb-modal__thumb img');
  if (headImg) {
    const r = headImg.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) {
      return { src: headImg.currentSrc || headImg.src, rect: r };
    }
  }

  /* fallback: ภาพจาก card */
  const cardImg = cardEl?.querySelector('.dpb-opt-imgwrap img') || cardEl?.querySelector('img');
  if (cardImg) {
    const r = cardImg.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) {
      return { src: cardImg.currentSrc || cardImg.src, rect: r };
    }
  }
  return null;
}

function ensureVariantModalDOM(){
  let modal = document.getElementById('dpb-variant-modal');
  if (modal) return modal;
  modal = document.createElement('div');
  modal.id = 'dpb-variant-modal';
  modal.className = 'dpb-modal';
  modal.setAttribute('aria-hidden','true');
  modal.innerHTML = `
    <div class="dpb-modal__backdrop"></div>
    <div class="dpb-modal__panel" role="dialog" aria-modal="true">
      <button class="dpb-modal__close" aria-label="Close">✕</button>
      <div class="dpb-modal__header">
        <div class="dpb-modal__thumb"><img alt="" /></div>
        <div class="dpb-modal__title-group"></div>
      </div>
      <div class="dpb-modal__body"></div>
      <div class="dpb-modal__footer">
        <button class="dpb-btn dpb-btn-primary" data-role="confirm" id="dpb-variant-confirm">ยืนยัน</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  return modal;
}


function ensureVariantConfirmButton(modal){
  if (!modal) return null;
  let btn =
    modal.querySelector('[data-role="confirm"]') ||
    modal.querySelector('#dpb-variant-confirm') ||
    modal.querySelector('#variant-confirm');
  if (!btn) {
    let footer =
      modal.querySelector('.dpb-modal__footer') ||
      (() => {
        const f = document.createElement('div');
        f.className = 'dpb-modal__footer';
        modal.querySelector('.dpb-modal__panel')?.appendChild(f);
        return f;
      })();
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dpb-btn dpb-btn-primary';
    btn.setAttribute('data-role','confirm');
    btn.id = 'dpb-variant-confirm';
    btn.textContent = 'ยืนยัน';
    footer.appendChild(btn);
  }
  return btn;
}

function openVariantModalForOption(key, { cardEl } = {}){
  const modal = ensureVariantModalDOM();
  const btnOk = ensureVariantConfirmButton(modal);
  const back    = modal.querySelector('.dpb-modal__backdrop');
  const closeB  = modal.querySelector('.dpb-modal__close');
  const op = (state.meta.options||[]).find(o=>o.key===key) || { key, type:'attach', variants:[] };
  
  // รับค่า isDrawerDisabled เข้ามา
  const { back:bk, closeB:cb, getVariant, getQty, isDrawerDisabled } = buildVariantModalUI(modal, op);
  
  const close = ()=>{
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    document.removeEventListener('keydown', onEsc, true);
  };
  const onEsc = (e)=>{
    if(e.key === 'Escape' && modal.classList.contains('is-open')) close();
  };
  (bk || back)?.addEventListener('click', close);
  (cb || closeB)?.addEventListener('click', close);
  document.addEventListener('keydown', onEsc, true);
  
  btnOk.replaceWith(btnOk.cloneNode(true));
  const freshOk = ensureVariantConfirmButton(modal); 

  // ===================================================
  // [ส่วนที่แก้ไข] แยก Logic การคลิกปุ่มยืนยัน
  // ===================================================
  if (isDrawerDisabled) {
    // 1. ปรับแต่งปุ่มให้ดูเหมือนถูกปิดใช้งาน
    freshOk.style.opacity = '0.5';
    freshOk.style.cursor = 'not-allowed';
    
    // 2. เมื่อปุ่มถูกคลิก ให้ไปเล่น Animation ที่ป้ายแจ้งเตือนแทน
    freshOk.addEventListener('click', ()=>{
      const alertBox = modal.querySelector('.dpb-drawer-alert');
      if (alertBox) {
        // ใช้ Web Animations API เพื่อทำเอฟเฟกต์สั่น (Shake) โดยไม่ต้องเขียน CSS เพิ่ม
        alertBox.animate([
          { transform: 'translateX(0)' },
          { transform: 'translateX(-8px)' },
          { transform: 'translateX(8px)' },
          { transform: 'translateX(-8px)' },
          { transform: 'translateX(8px)' },
          { transform: 'translateX(0)' }
        ], { 
          duration: 400, 
          easing: 'ease-in-out' 
        });
      }
    });
    // หมายเหตุ: สังเกตว่าเราไม่ได้ใส่ { once:true } เพื่อให้ผู้ใช้กดกี่ครั้งก็เด้งเตือนทุกครั้ง
    
  } else {
    // 1. คืนค่าสไตล์ปุ่มให้เป็นปกติ
    freshOk.style.opacity = '1';
    freshOk.style.cursor = 'pointer';
    
    // 2. เมื่อปุ่มถูกคลิก ให้ทำงานตามระบบเพิ่มลงตะกร้าแบบเดิม
    freshOk.addEventListener('click', ()=>{
      const qty      = getQty();
      const variant  = getVariant();
      const pick     = typeof getPreferredVariantImageAndRect === 'function'
        ? getPreferredVariantImageAndRect({ modal, cardEl })
        : null;

      const op     = (window.state?.meta?.options || []).find(o => o.key === key) || {};
      const oType  = String(op.type || '').toLowerCase();
      const isHole = ['hole_rect','hole_circle','track'].includes(oType);

      /* เปิด pp3 / call addOption ก่อน */
      if (typeof window.addOptionWithVariantAndQty === 'function') {
        window.addOptionWithVariantAndQty(key, variant, qty);
      }

      /* แล้วค่อยปิด modal เก่าหลัง 100ms */
      setTimeout(() => {
        close();
      }, 0);

      if (!isHole && pick && pick.src && pick.rect && typeof window.flyBitmapToCart === 'function') {
        window.flyBitmapToCart(pick.src, pick.rect);
      }

    }, { once:true });
  }
  
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden','false');
}

(function ensureAddOptionWithVariantAndQty(){
  if (typeof window.addOptionWithVariantAndQty === 'function') return;
  window.addOptionWithVariantAndQty = function addOptionWithVariantAndQty(optKey, variantName, qty){
    const op = (state.meta.options||[]).find(o=>o.key===optKey) || { key:optKey, type:'attach', variants:[] };
    const vName = String(variantName||'').trim();
    const now = Date.now();
    const isAttach = String(op.type||'').toLowerCase()==='attach';
    const hasVariants = Array.isArray(op.variants) && op.variants.length > 0;
    state.selectedOptions[optKey] = state.selectedOptions[optKey] || { count: 0 };
    state.optConfig[optKey]       = state.optConfig[optKey]       || [];
    for(let i=0; i<qty; i++){
      state.selectedOptions[optKey].count += 1;
      const cfg = {
        ...(typeof defaultCfgFor==='function' ? defaultCfgFor(optKey) : {}),
        type: isAttach ? 'attach' : String(op.type||'hole_rect'),
        variant: hasVariants ? vName : '',
        addedAt: now + i,
        uid: `uid_${optKey}_${now}_${i}_${Math.random().toString(36).slice(2,7)}`
      };
      state.optConfig[optKey].push(cfg);
    }
    if (typeof updateCartBadge === 'function') updateCartBadge();
    if (typeof buildOptConfig === 'function') buildOptConfig();
    if (typeof postProcessCartOrder === 'function') postProcessCartOrder(); 
    if (typeof scheduleRedraw === 'function') scheduleRedraw();
    console.log(`Added ${qty} x ${optKey} (${vName}) to cart.`); 
  };
})();

(function forceModalOnOptionClicks(){
  if (window.__dpbForceModalBound) return;
  window.__dpbForceModalBound = true;
  document.addEventListener('click', function(e){
    const card = e.target.closest('.dpb-opt-item');
    if (!card) return;
    const host = document.getElementById('dpb-opt-list');
    if (!host || !host.contains(card)) return;
    if (e.button !== 0) return;
    const modal = document.getElementById('dpb-variant-modal');
    if (modal && modal.classList.contains('is-open')) return;
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    const key = card.dataset.key;
    if (!key) return;
    if (typeof window.openVariantModalForOption === 'function') {
      window.openVariantModalForOption(key, { cardEl: card });
    } else if (typeof window.openVariantModalFor === 'function') {
      window.openVariantModalFor(key, { cardEl: card });
    }
  }, true); 
})();

if (typeof window.openVariantModalFor !== 'function') {
  window.openVariantModalFor = function(key, opts){
    return openVariantModalForOption(key, opts);
  };
}

(function ensureCompatForOldBindings(){
  if (typeof window.ensureVariantModalBindings === 'function') return;
  window.ensureVariantModalBindings = function(){};
})();

(function bindOptionGridToVariantModal(){
  const optHost = document.getElementById('dpb-opt-list');
  if(!optHost) return;
  if (optHost.dataset.modalBound === '1') return;
  optHost.dataset.modalBound = '1';
  optHost.addEventListener('click', (e)=>{
    const card = e.target.closest('.dpb-opt-item');
    if(!card || !optHost.contains(card)) return;
    const key = card.dataset.key;
    if(!key) return;
    openVariantModalForOption(key, { cardEl: card });
  }, true);
})();

if (typeof window.isPlacementReady !== 'function'){
window.isPlacementReady = function isPlacementReady(cfg, op){
    cfg = cfg || {};
    op  = op  || {};
    var type = String(op.type || '').toLowerCase();
    if (type === 'attach') return true;
    var isCircle = (op.type === 'hole_circle');
    var wOk = Number(cfg.w) > 0;
    var hOk = isCircle ? true : (Number(cfg.h) > 0);
    var from = String(cfg.from || 'top').toLowerCase();
    var place = String(cfg.place || 'left').toLowerCase();
    var fromOk  = (from === 'top' || from === 'bottom' || from === 'left' || from === 'right' || from === 'center');
    var placeOk = (place === 'left' || place === 'right' || place === 'center' || place === 'top' || place === 'bottom');
    return !!(wOk && hOk && fromOk && placeOk);
};
}

function applyPlacementConstraints(card, cfg){
  const fromSel       = card.querySelector('select[name="from"]');
  const placeSel      = card.querySelector('select[name="place"]');
  const offsetXInput = card.querySelector('input[name="offsetX"]');
  const offsetYInput = card.querySelector('input[name="offsetY"]');
  if(!fromSel || !placeSel) return;
  const pos = (cfg.pos || 'main').toLowerCase();
  const isRotated = !!cfg.rotate; 
  let fromOpts = [];
  let placeOpts = [];
  if(pos === 'main'){
    if (isRotated) {
        fromOpts = [{v:'top', t:'ด้านบน'}, {v:'center', t:'ตรงกลาง'}, {v:'bottom', t:'ด้านล่าง'}];
    } else {
        fromOpts = [{v:'top', t:'ด้านบน'}, {v:'bottom', t:'ด้านล่าง'}];
    }
    placeOpts = [{v:'left', t:'ด้านซ้าย'}, {v:'center', t:'ตรงกลาง'}, {v:'right', t:'ด้านขวา'}];
  } else if (pos === 'left') {
    fromOpts = [{v:'left', t:'ด้านซ้าย'}];
    placeOpts = [{v:'bottom', t:'ด้านล่าง'},{v:'center', t:'ตรงกลาง'},{v:'top', t:'ด้านบน'}];
    cfg.from = 'left';
  } else if (pos === 'right') {
    fromOpts = [{v:'right', t:'ด้านขวา'}];
    placeOpts = [{v:'top', t:'ด้านบน'},{v:'center', t:'ตรงกลาง'},{v:'bottom', t:'ด้านล่าง'}];
    cfg.from = 'right';
  }
  const renderOpts = (sel, opts, currentVal) => {
     const exists = opts.some(o => o.v === currentVal);
     sel.innerHTML = opts.map(o => `<option value="${o.v}">${o.t}</option>`).join('');
     if(exists) sel.value = currentVal;
     else {
         sel.value = opts[0].v;
         return opts[0].v;
     }
     return currentVal;
  };
  cfg.from  = renderOpts(fromSel, fromOpts, String(cfg.from||''));
  cfg.place = renderOpts(placeSel, placeOpts, String(cfg.place||''));
  if(offsetXInput){
    const isCenterX = (cfg.place === 'center');
    offsetXInput.disabled = isCenterX;
    if(isCenterX){
      offsetXInput.value = '';
      cfg.offsetX = 0;
      setFieldError(offsetXInput, '');
    } else {
      let v = parseFloat(offsetXInput.value);
      if(isNaN(v)) v = cfg.offsetX || (isRotated ? 5 : 10);
      offsetXInput.value = String(v);
      cfg.offsetX = v;
    }
  }
  if(offsetYInput){
    const isCenterY = (cfg.from === 'center');
    offsetYInput.disabled = isCenterY;
    if(isCenterY){
      offsetYInput.value = '';
      cfg.offsetY = 0;
      setFieldError(offsetYInput, '');
    } else {
      let v = parseFloat(offsetYInput.value);
      if(isNaN(v)) v = cfg.offsetY || 5;
      offsetYInput.value = String(v);
      cfg.offsetY = v;
    }
  }
}

function postProcessCartOrder(){
  const list = document.querySelector('.dpb-cart-body');
  if(!list) return;
  const items = Array.from(list.querySelectorAll('.dpb-cart-item'));
  if(items.length <= 1) return;
  const getMeta = (key)=> (state.meta.options||[]).find(o=>o.key===key) || {};
  const getCfg  = (key, idx)=> (state.optConfig?.[key]||[])[idx];
  const PENALTY_NO_VARIANT_ATTACH = 1e15;
  const scored = items.map(el=>{
    const key = el.dataset.key;
    const index = Number(el.dataset.index||0);
    const cfg = getCfg(key, index) || {};
    const op  = getMeta(key);
    let w = -(cfg.addedAt || 0);
    const hasVariants = Array.isArray(op.variants) && op.variants.length > 0;
    const isAttachNoVar = String(op.type||'').toLowerCase()==='attach' && !hasVariants;
    if(isAttachNoVar) w += PENALTY_NO_VARIANT_ATTACH;
    return {el, weight: w};
  });
  scored.sort((a,b)=> a.weight - b.weight);
  const frag = document.createDocumentFragment();
  scored.forEach(s=> frag.appendChild(s.el));
  list.appendChild(frag);
}

    function roundedPath(target, x,y,w,h, rTL,rTR,rBR,rBL){
      const tl=Math.min(rTL,w/2,h/2), tr=Math.min(rTR,w/2,h/2),
            br=Math.min(rBR,w/2,h/2), bl=Math.min(rBL,w/2,h/2);
      target.beginPath();
      target.moveTo(x+tl,y); target.lineTo(x+w-tr,y); target.quadraticCurveTo(x+w,y,x+w,y+tr);
      target.lineTo(x+w,y+h-br); target.quadraticCurveTo(x+w,y+h,x+w-br,y+h);
      target.lineTo(x+bl,y+h); target.quadraticCurveTo(x,y+h,x,y+h-bl);
      target.lineTo(x,y+tl); target.quadraticCurveTo(x,y,x+tl,y); target.closePath();
    }

function roundedPathNoClamp(ctx, x, y, w, h, rtl, rtr, rbr, rbl){
  const tl = Math.max(0, Math.min(rtl, w, h));
  const tr = Math.max(0, Math.min(rtr, w, h));
  const br = Math.max(0, Math.min(rbr, w, h));
  const bl = Math.max(0, Math.min(rbl, w, h));
  ctx.beginPath();
  ctx.moveTo(x + tl, y);
  ctx.lineTo(x + w - tr, y);
  if (tr > 0) ctx.arcTo(x + w, y, x + w, y + tr, tr); else ctx.lineTo(x + w, y);
  ctx.lineTo(x + w, y + h - br);
  if (br > 0) ctx.arcTo(x + w, y + h, x + w - br, y + h, br); else ctx.lineTo(x + w, y + h);
  ctx.lineTo(x + bl, y + h);
  if (bl > 0) ctx.arcTo(x, y + h, x, y + h - bl, bl); else ctx.lineTo(x, y + h);
  ctx.lineTo(x, y + tl);
  if (tl > 0) ctx.arcTo(x, y, x + tl, y, tl); else ctx.lineTo(x, y);
  ctx.closePath();
}

    function fillRoundedRect(x,y,w,h,rTL,rTR,rBR,rBL, style){
      if(style) ctx.fillStyle=style;
      roundedPath(ctx,x,y,w,h,rTL,rTR,rBR,rBL);
      ctx.fill();
    }
	function fillSmartRoundedRect(ctx, x, y, w, h, tl, tr, br, bl) {
  // คำนวน Limit ตามขนาดจริงของด้าน (ไม่ใช่แค่ครึ่งเดียวแบบเดิม)
  // ตรวจสอบด้านซ้าย (Left Edge)
  if (tl + bl > h) {
    const scale = h / (tl + bl);
    tl *= scale; bl *= scale;
  }
  // ตรวจสอบด้านขวา (Right Edge)
  if (tr + br > h) {
    const scale = h / (tr + br);
    tr *= scale; br *= scale;
  }
  // ตรวจสอบด้านบน (Top Edge)
  if (tl + tr > w) {
    const scale = w / (tl + tr);
    tl *= scale; tr *= scale;
  }
  // ตรวจสอบด้านล่าง (Bottom Edge)
  if (bl + br > w) {
    const scale = w / (bl + br);
    bl *= scale; br *= scale;
  }

  ctx.beginPath();
  ctx.moveTo(x + tl, y);
  ctx.lineTo(x + w - tr, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + tr);
  ctx.lineTo(x + w, y + h - br);
  ctx.quadraticCurveTo(x + w, y + h, x + w - br, y + h);
  ctx.lineTo(x + bl, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - bl);
  ctx.lineTo(x, y + tl);
  ctx.quadraticCurveTo(x, y, x + tl, y);
  ctx.closePath();
  ctx.fill();
}

    function drawTicksH(x1,x2,y,color){
      ctx.strokeStyle=color; ctx.lineWidth=1.3;
      ctx.beginPath(); ctx.moveTo(x1,y); ctx.lineTo(x2,y); ctx.stroke();
      endDot(x1, y, color); endDot(x2, y, color);
    }

    function drawTicksV(y1,y2,x,color){
      ctx.strokeStyle=color; ctx.lineWidth=1.3;
      ctx.beginPath(); ctx.moveTo(x,y1); ctx.lineTo(x,y2); ctx.stroke();
      endDot(x, y1, color); endDot(x, y2, color);
    }

function dimH(x1, x2, yWanted, label, dir, textPos = 'on', opts = {}){
  if(x2 < x1){ const t = x1; x1 = x2; x2 = t; }
  const c = getOutColor();
  let auto = (label === '' || label === null || typeof label === 'undefined' || label === true);
  if(auto){
    const sc = (typeof deskScale === 'function') ? deskScale() : 1;
    const cm = Math.round((Math.abs(x2 - x1) / sc) * 10) / 10;
    label = `${cm} cm`;
  }
  const SAFE_TOP    = (typeof PAD?.top === 'number' ? PAD.top : 20) + 8;
  const SAFE_BOTTOM = canvas.height - ((typeof PAD?.bottom === 'number' ? PAD.bottom : 20) + 5);
  let y = yWanted;
  if(dir === 'up'   && y < SAFE_TOP)    y = SAFE_TOP;
  if(dir === 'down' && y > SAFE_BOTTOM) y = SAFE_BOTTOM;
  const tick  = 8;
  const gapPx = Number.isFinite(opts.gapPx) ? opts.gapPx : 18;
  let textDy = 0;
  if(textPos === 'above') textDy = -gapPx;
  if(textPos === 'below') textDy = +gapPx;

/* pulse */
const isActive = !!(opts.dimKey && window._dpbDimFocus === opts.dimKey);
const pulse    = isActive ? (window._dpbDimPulse ?? 1) : 1;

ctx.save();
ctx.globalAlpha = isActive ? (0.5 + 0.5 * pulse) : 1;
ctx.globalCompositeOperation = 'source-over';
ctx.strokeStyle = isActive ? '#ff2020' : c;
ctx.fillStyle   = isActive ? '#ff2020' : c;
ctx.lineWidth   = isActive ? (3.5 + 2.5 * (1 - pulse)) : 1.3;
ctx.shadowColor = isActive ? '#ff0000' : 'transparent';
ctx.shadowBlur  = isActive ? (14 * (1 - pulse)) : 0;

  ctx.beginPath();
  ctx.moveTo(x1, y); ctx.lineTo(x2, y); ctx.stroke();

  ctx.beginPath();
  ctx.moveTo(x1, y - tick); ctx.lineTo(x1, y + tick);
  ctx.moveTo(x2, y - tick); ctx.lineTo(x2, y + tick);
  ctx.stroke();

  const midX = (x1 + x2) / 2;
  ctx.font         = '400 18px Prompt, sans-serif';
  ctx.textAlign    = 'center';
  ctx.textBaseline = 'middle';
  ctx.lineWidth    = 0;
  ctx.shadowBlur   = isActive ? (10 * (1 - pulse)) : 0;
  ctx.fillText(label, midX, y + textDy);

  ctx.restore();
}


function dimV(y1, y2, xWanted, label, xOffset = 0, textPos = 'center', opts = {}) {
  if (y2 < y1) { const t = y1; y1 = y2; y2 = t; }
  const c = getOutColor?.() || '#000';
  const SAFE_LEFT  = (typeof PAD?.left  === 'number' ? PAD.left  : 20) + 8;
  const SAFE_RIGHT = canvas.width - ((typeof PAD?.right === 'number' ? PAD.right : 20) + 8);
  let x = xWanted;
  if (x < SAFE_LEFT)  x = SAFE_LEFT;
  if (x > SAFE_RIGHT) x = SAFE_RIGHT;
  const tick = 8;

/* pulse */
const isActive = !!(opts.dimKey && window._dpbDimFocus === opts.dimKey);
const pulse    = isActive ? (window._dpbDimPulse ?? 1) : 1;

ctx.save();
ctx.globalAlpha = isActive ? (0.5 + 0.5 * pulse) : 1;
ctx.strokeStyle = isActive ? '#ff2020' : c;
ctx.lineWidth   = isActive ? (3.5 + 2.5 * (1 - pulse)) : 1.3;
ctx.shadowColor = isActive ? '#ff0000' : 'transparent';
ctx.shadowBlur  = isActive ? (22 * (1 - pulse)) : 0;

  ctx.beginPath(); ctx.moveTo(x, y1); ctx.lineTo(x, y2); ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(x - tick, y1); ctx.lineTo(x + tick, y1);
  ctx.moveTo(x - tick, y2); ctx.lineTo(x + tick, y2);
  ctx.stroke();
  ctx.restore();

const { rotateText = false, clockwise = true, textDx = 0, textDy = 0 } = opts;
  const midY = (y1 + y2) / 2;
  const tx   = x + xOffset;
  const ty   = midY;

  ctx.save();
  ctx.fillStyle    = isActive ? '#ff2020' : c;       /* ← สีแดงเมื่อ active */
  ctx.font         = '400 18px Prompt, sans-serif';
  ctx.textAlign    = 'center';
  ctx.textBaseline = 'middle';
  ctx.globalAlpha  = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor  = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur   = isActive ? (14 * (1 - pulse)) : 0;

  if (rotateText) {
    ctx.translate(tx, ty);
    ctx.rotate(clockwise ? Math.PI / 2 : -Math.PI / 2);
    ctx.fillText(String(label), textDx, textDy);
  } else {
    ctx.fillText(String(label), tx + textDx, ty + textDy);
  }
  ctx.restore();
}

function dimV_opt(y1, y2, xWanted, label, xOffset = 0, opts = {}) {
  if (y2 < y1) { const t=y1; y1=y2; y2=t; }
  const c = getOutColor?.() || '#000';
  const tick = 8;
  ctx.save();
  ctx.strokeStyle = c;
  ctx.lineWidth = 1.3;
  ctx.beginPath(); ctx.moveTo(xWanted,y1); ctx.lineTo(xWanted,y2); ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(xWanted-tick,y1); ctx.lineTo(xWanted+tick,y1);
  ctx.moveTo(xWanted-tick,y2); ctx.lineTo(xWanted+tick,y2);
  ctx.stroke();
  ctx.restore();
  const raw = String(label || '');
  const [num, unit] = raw.split(' ');
  const midY = (y1+y2)/2;
  const tx = xWanted + xOffset;
  const gap = 14;
  ctx.save();
  ctx.fillStyle = c;
  ctx.font = '400 16px Prompt,sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(num || raw,  tx, midY - gap/2);
  ctx.fillText(unit || '',  tx, midY + gap/2);
  ctx.restore();
}

(function setDefaultToday(){
  var el = document.getElementById('dpb-date');
  if (!el || el.value) return; 
  var tzNow = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Bangkok' }));
  var yyyy = tzNow.getFullYear();
  var mm   = String(tzNow.getMonth() + 1).padStart(2, '0');
  var dd   = String(tzNow.getDate()).padStart(2, '0');
  el.value = `${yyyy}-${mm}-${dd}`;
})();

function measureInfoGrid(){
  const name = document.getElementById('dpb-customer').value || '-';
  const platform = document.getElementById('dpb-platforms').value || '-';

  const d = document.getElementById('dpb-date').value;
  const prettyDate = d
    ? (()=>{ const [yy,mm,dd]=d.split('-'); return `${dd}/${mm}/${String(yy).slice(2)}`; })()
    : (()=>{ const t=new Date(); const dd=String(t.getDate()).padStart(2,'0'); const mm=String(t.getMonth()+1).padStart(2,'0'); const yy=String(t.getFullYear()).slice(2); return `${dd}/${mm}/${yy}`; })();
  
  const typeText = document.getElementById('dpb-type').selectedOptions[0]?.text || '-';
  const topKey = document.getElementById('dpb-top-color').value;
  const colorObj = (state.meta.colors||[]).find(c=>c.key===topKey);
  let topName = colorObj?.name || topKey;
  
  // Logic เดิมในการจัดการ Solid Trim
  if (typeof DPB_SOLID_KEYS !== 'undefined' && DPB_SOLID_KEYS.includes(String(topKey))) {
      const trimVal = document.getElementById('dpb-solid-trim')?.value || 'untrim';
      const trimLabel = (trimVal === 'trim') ? '(ทริมเมอร์สันขอบ)' : '(ไม่ทริมเมอร์สันขอบ)';
      topName += ` ${trimLabel}`;
  } else {
      if (topKey && topName.trim().toLowerCase() !== topKey.trim().toLowerCase()) {
          topName += ` (${topKey})`;
      } else if (!colorObj) {
          topName = topKey;
      }
  }

  // --- ส่วนที่เพิ่ม/แก้ไข: Logic สำหรับเปลี่ยน Whiteboard เป็น White บน Canvas ---
  // ใช้ Regular Expression เพื่อเปลี่ยนคำว่า Whiteboard เป็น White (ไม่สนใจตัวพิมพ์เล็ก-ใหญ่)
  let displayTopName = topName.replace(/Whiteboard/gi, 'White');
  // -----------------------------------------------------------------------

  const legKey = document.getElementById('dpb-legs').value;
  const legName = (state.meta.legs||[]).find(c=>c.key===legKey)?.name||legKey;
  
  const headerItems = [
    { label: 'Name:', value: name },
    { label: 'Platform:', value: platform },
    { label: 'Date:', value: prettyDate },
    { label: 'Type:', value: typeText },
    { label: 'Leg:', value: legName }
  ];

  // เปลี่ยนจาก topName เป็น displayTopName ที่เราแก้คำแล้ว
  const topItem = { label: 'Top:', value: displayTopName };

  ctx.font = INFO.rowFont;
  let totalHeaderWidth = 0;
  const GAP = 25; 
  
  headerItems.forEach((item, idx) => {
      const wLabel = ctx.measureText(item.label).width;
      const wValue = ctx.measureText(' ' + item.value).width;
      item.width = wLabel + wValue;
      totalHeaderWidth += item.width;
      if (idx < headerItems.length - 1) totalHeaderWidth += GAP;
  });

  const topInfoWidth = ctx.measureText(topItem.label).width + ctx.measureText(' ' + topItem.value).width;

  return {
    headerItems,
    totalHeaderWidth,
    headerGap: GAP,
    topItem,
    topInfoWidth,
    height: INFO.rowLH 
  };
}

function drawInfoOverlayOnDesk(meas, coords){
  ctx.font = INFO.rowFont;
  const ink = getOutColor(); 
  if (coords.headerY !== undefined && coords.headerCenterX !== undefined) {
      let currentX = coords.headerCenterX - (meas.totalHeaderWidth / 2);
      const y = coords.headerY;

      meas.headerItems.forEach((item, idx) => {
          ctx.textBaseline = 'top'; 
          ctx.fillStyle = INFO.topic || '#a37d13';
          ctx.textAlign = 'left';
          ctx.fillText(item.label, currentX, y);
          const wLabel = ctx.measureText(item.label).width;
          ctx.fillStyle = ink;
          ctx.fillText(' ' + item.value, currentX + wLabel, y);
          currentX += item.width + meas.headerGap;
      });
  }

  if (coords.topY !== undefined && coords.topCenterX !== undefined) {
      const item = meas.topItem;
      const startX = coords.topCenterX - (meas.topInfoWidth / 2);
      const y = coords.topY;

      ctx.textBaseline = 'top';
      
      ctx.fillStyle = INFO.topic || '#a37d13';
      ctx.textAlign = 'left';
      ctx.fillText(item.label, startX, y);

      const wLabel = ctx.measureText(item.label).width;
      
      ctx.fillStyle = ink;
      ctx.fillText(' ' + item.value, startX + wLabel, y);
  }
}

function dpb_computeInfoOverlayXY(meas){
  const typeNow = (byId('dpb-type')?.value || '').toLowerCase();
  const sc      = deskScale();
  const px      = v => v*sc;
  const rect1 = state?.boxes?.main; 
  const rect2 = state?.boxes?.arm;  
  if ((typeNow==='l2' || typeNow==='l3') && rect1 && rect2 && isFinite(meas?.colsWidth)){
    const side = (byId('dpb-aside')?.value || 'right').toLowerCase();
    const y = Math.round(rect1.y + rect1.h + px(20));
    const centerOffset = (rect1.w - rect2.w) / 2;
    const centerX = (side === 'left')
      ? (rect2.x + rect2.w - centerOffset) 
      : (rect2.x + centerOffset);          
    const x = Math.round(centerX - (meas.colsWidth / 2));
    return { x, y };
  }
  if (rect1 && isFinite(meas?.colsWidth) && isFinite(meas?.height)){
    const x = Math.round(rect1.x + (rect1.w - meas.colsWidth)/2);
    const y = Math.round(rect1.y + rect1.h - meas.height);
    return { x, y };
  }
  return { x: 0, y: 0 };
}

function getDeskBottomPaddingCm(){
  const t = document.getElementById('dpb-type')?.value;
  if(t === 'custom_manual') return 7.35;
  return 0;
}

function measureTotalHeight() {
    // [FIX PART 1] กำหนดความสูงสำหรับ 3D ให้ชัดเจน
    if (window.dpbViewMode === '3d') {
        // คืนค่าความสูงที่ต้องการสำหรับ 3D (1200px กำลังสวยสำหรับจอส่วนใหญ่)
        return 1000; 
    }

    // --- ส่วนล่างนี้เป็น Logic เดิมสำหรับ 2D (ห้ามแก้) ---
    const byId = function(id) { return document.getElementById(id); };
    const t = byId('dpb-type')?.value || 'rect';
    
    let deskH = (t==='l2'||t==='l3') ? (typeof ldeskHeight === 'function' ? ldeskHeight() : 500) : (typeof rectDeskHeight === 'function' ? rectDeskHeight() : 500);
    
    if (!isFinite(deskH) || deskH < 0) deskH = 0;
    const MAX_DESK_H = (typeof RAW_MAX_DESK_H !== 'undefined') ? RAW_MAX_DESK_H : 4096;
    deskH = Math.min(deskH, MAX_DESK_H);
    
    const HEADER_RESERVED_HEIGHT = 150; 
    const DESK_BOTTOM_SPACE      = 80; 
    const GAP_BETWEEN_OPTS       = 0; 
    const BOTTOM_PADDING_FINAL   = 40;  

    const sc = (typeof deskScale === 'function') ? deskScale() : 1;
    const extraCm = (typeof getDeskBottomPaddingCm === 'function') ? getDeskBottomPaddingCm() : 0;
    const extraPx = extraCm * sc;

    let optH = 0;
    const items = (typeof getItems === 'function') ? getItems() : [];
    
    if (items.length > 0) {
        const canvas = document.getElementById('dpb-canvas') || document.querySelector('canvas');
        if(canvas) {
            const totalInnerW = canvas.width - ((typeof PAD !== 'undefined' && PAD.left ? PAD.left : 0) + (typeof PAD !== 'undefined' && PAD.right ? PAD.right : 0));
            const cw  = Math.max(1, (typeof CARD!=='undefined'?CARD.cardW:160)|0);
            const cgap = Math.max(0, (typeof CARD!=='undefined'?CARD.gap:0)|0);
            const ch  = Math.max(0, (typeof CARD!=='undefined'?CARD.imgH:120)|0);
            const textH = (typeof OPTCARD!=='undefined'?OPTCARD.textH:44);
            
            let perRow = Math.max(1, Math.floor((totalInnerW + cgap) / (cw + cgap)));
            perRow = Math.min(perRow, items.length);
            const rows = Math.ceil(items.length / perRow);
            
            optH = rows * (ch + textH) + Math.max(0, rows-1) * cgap;
            
            const MAX_OPT_H = (typeof RAW_MAX_OPT_H !== 'undefined') ? RAW_MAX_OPT_H : 6000;
            optH = Math.min(optH, MAX_OPT_H);
        }
    }
    
    let total = HEADER_RESERVED_HEIGHT + deskH + extraPx + DESK_BOTTOM_SPACE + GAP_BETWEEN_OPTS + optH + BOTTOM_PADDING_FINAL;
    
    const MAX_CANVAS = (typeof GLOBAL_MAX_CANVAS !== 'undefined') ? GLOBAL_MAX_CANVAS : 12000;
    return Math.min(MAX_CANVAS, Math.ceil(total));
}


function drawOptionsGridInBox(x, y, boxW){
  const items = (typeof getItems === 'function') ? getItems() : [];
  if(items.length === 0) return {h: 0, rows:0};
  const cw  = Math.max(1, (typeof CARD!=='undefined'?CARD.cardW:160)|0);
  const ch  = Math.max(0, (typeof CARD!=='undefined'?CARD.imgH:120)|0);
  const gap = Math.max(0, (typeof CARD!=='undefined'?CARD.gap:0)|0);
  const textH = (typeof OPTCARD!=='undefined'?OPTCARD.textH:44);
  const cardH = ch + textH;
  if(boxW < cw) return {h: 0, rows:0};
  let perRow = Math.max(1, Math.floor((boxW + gap) / (cw + gap)));
  perRow = Math.min(perRow, items.length);
  const rows = Math.ceil(items.length / perRow);
  let idx = 0;
  let yCursor = y;
  const getImg = (url)=>{
    if(!url) return null;
    window.state = window.state || {};
    state.optImgCache = state.optImgCache || {};
    const cache = state.optImgCache[url];
    if(cache && cache.complete) return cache;
    if(!cache){
      const im = new Image(); im.crossOrigin = 'anonymous'; im.src = url;
      im.onload = ()=>{ if(typeof scheduleRedraw==='function') scheduleRedraw(); };
      state.optImgCache[url] = im;
    }
    return state.optImgCache[url].complete ? state.optImgCache[url] : null;
  };
  for(let r=0; r<rows; r++){
    const countThisRow = Math.min(perRow, items.length - idx);
    const rowWidth = countThisRow * cw + (countThisRow - 1) * gap;
    const startX = x + Math.max(0, (boxW - rowWidth) / 2);
    for(let i=0; i<countThisRow; i++){
      const it = items[idx++];
      const cx = startX + i*(cw+gap);
      const cy = yCursor;
      ctx.save();
      if(typeof CARD !== 'undefined' && CARD.shadow){
          ctx.shadowColor = CARD.shadow; ctx.shadowBlur = 10; ctx.shadowOffsetY = 4;
      }
      ctx.fillStyle = '#ffffff';
      const rad = (typeof CARD!=='undefined'?CARD.radius:14);
      if(ctx.roundRect){ ctx.beginPath(); ctx.roundRect(cx, cy, cw, cardH, rad); ctx.fill(); } 
      else { ctx.fillRect(cx, cy, cw, cardH); }
      ctx.restore();
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(cx + rad, cy);
      ctx.lineTo(cx + cw - rad, cy);
      ctx.quadraticCurveTo(cx + cw, cy, cx + cw, cy + rad);
      ctx.lineTo(cx + cw, cy + ch);
      ctx.lineTo(cx,        cy + ch);
      ctx.lineTo(cx,        cy + rad);
      ctx.quadraticCurveTo(cx, cy, cx + rad, cy);
      ctx.closePath();
      ctx.clip();
      ctx.fillStyle = '#f3f4f6'; ctx.fillRect(cx, cy, cw, ch);
      const im = getImg(it.img);
      if(im && typeof drawImageCover === 'function'){ drawImageCover(im, cx, cy, cw, ch); }
      ctx.restore();
      if((it.count||0) > 0){
        const pad = (typeof OPTCARD!=='undefined'?OPTCARD.badgePad:8);
        const R   = (typeof OPTCARD!=='undefined'?OPTCARD.badgeR:11);
        const bx = cx + cw - pad - R, by = cy + pad + R;
        ctx.save();
        ctx.beginPath(); ctx.arc(bx, by, R, 0, Math.PI*2);
        ctx.fillStyle = '#000'; ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = '400 12px Prompt, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(String(it.count), bx, by + 0.5);
        ctx.restore();
      }
      ctx.save();
      ctx.textAlign = 'center';
      const textAreaTop = cy + ch;
      const textAreaCenter = textAreaTop + (textH / 2);
      const hasVariant = (it.detail || '').trim().length > 0;
      if (hasVariant) {
          ctx.font = (typeof OPTCARD!=='undefined'?OPTCARD.nameFont:'400 13px Prompt, sans-serif');
          ctx.fillStyle = (typeof UI_INK!=='undefined'?UI_INK:'#a37d13');
          ctx.textBaseline = 'bottom'; 
          ctx.fillText(it.name || '', cx + cw/2, textAreaCenter - 1);
          ctx.font = (typeof OPTCARD!=='undefined'?OPTCARD.variantFont:'400 12px Prompt, sans-serif');
          ctx.fillStyle = '#000';
          ctx.textBaseline = 'top';
          ctx.fillText(it.detail, cx + cw/2, textAreaCenter + 1);
      } else {
          ctx.font = (typeof OPTCARD!=='undefined'?OPTCARD.nameFont:'400 13px Prompt, sans-serif');
          ctx.fillStyle = (typeof UI_INK!=='undefined'?UI_INK:'#a37d13');
          ctx.textBaseline = 'middle';
          ctx.fillText(it.name || '', cx + cw/2, textAreaCenter);
      }
      ctx.restore();
    }
    yCursor += (cardH + gap);
  }
  const totalH = rows * cardH + Math.max(0, rows-1) * gap;
  return { h: totalH, rows };
}

state.theme = state.theme || {};
if (typeof state.theme.userPickedIn  === 'undefined') state.theme.userPickedIn  = false;
if (typeof state.theme.userPickedOut === 'undefined') state.theme.userPickedOut = false;

function DPB_detectTopName(){
  try{
    const elC = document.getElementById('dpb-top-color');
    if (elC){
      const opt = elC.selectedOptions && elC.selectedOptions[0];
      if (opt && opt.text) return String(opt.text);
      if (elC.value) return String(elC.value);
    }
    const el = document.getElementById('dpb-top');
    if (el){
      const opt = el.selectedOptions && el.selectedOptions[0];
      if (opt && opt.text) return String(opt.text);
      if (el.value) return String(el.value);
    }
    if (state?.theme?.topName) return String(state.theme.topName);
    if (state?.selection?.top?.name) return String(state.selection.top.name);
    if (Array.isArray(state?.meta?.tops)){
      const row = state.meta.tops.find(r => r?.selected || r?.active);
      if (row?.name) return String(row.name);
    }
  }catch(_){}
  return '';
}

function dpb_computeInfoOverlayXY(meas){
  const typeNow = (byId('dpb-type')?.value || '').toLowerCase();
  const sc      = deskScale();
  const px      = v => v*sc;
  const rect1 = state?.boxes?.main;
  const rect2 = state?.boxes?.arm; 
  if ((typeNow==='l2' || typeNow==='l3') && rect1 && rect2 && isFinite(meas?.colsWidth)){
    const side = (byId('dpb-aside')?.value || 'right').toLowerCase();
    const y = Math.round(rect1.y + rect1.h + px(20));
    const centerOffset = (rect1.w - rect2.w) / 2;
    const centerX = (side === 'left')
      ? (rect2.x + rect2.w - centerOffset)
      : (rect2.x + centerOffset);
    const x = Math.round(centerX - (meas.colsWidth / 2));
    return { x, y };
  }
  if (rect1 && isFinite(meas?.colsWidth) && isFinite(meas?.height)){
    const x = Math.round(rect1.x + (rect1.w - meas.colsWidth)/2);
    const y = Math.round(rect1.y + rect1.h - meas.height);
    return { x, y };
  }
  return { x: 0, y: 0 };
}

function computeAutoInColorFromTop(){
  const rawName = DPB_detectTopName();
  if (!rawName) return '#000000';
  const key = rawName.toLowerCase()
    .replace(/\(.*?\)/g,'')
    .replace(/\s+/g,' ')
    .trim();
  let pick = WM_TOP_COLOR_MAP[key];
  if(!pick){
    for (const w of Object.keys(WM_TOP_COLOR_MAP)){
      if (key.includes(w)) { pick = WM_TOP_COLOR_MAP[w]; break; }
    }
  }
  return (pick === 'white') ? '#ffffff' : '#000000';
}

function setColorGroupSelection(id, value){
  const group = document.getElementById(id);
  if(!group) return;
  const buttons = group.querySelectorAll('button');
  buttons.forEach(b=>{
    const on = (b.dataset.value === value);
    b.classList.toggle('active', on);
  });
  group.dataset.selected = value;
}

function DPB_syncColorGroup(id, hex){
  const g = document.getElementById(id); if (!g) return;
  const btns = g.querySelectorAll('button');
  btns.forEach(b=>b.classList.remove('active'));
  const match = Array.from(btns).find(b => (b.dataset.value||'').toLowerCase() === String(hex).toLowerCase());
  if (match){ match.classList.add('active'); g.dataset.selected = hex; }
}

function getInColor(){
  if (state?.theme?.userPickedIn) {
    return state.theme.colorIn
        || document.getElementById('dpb-color-in')?.dataset.selected
        || '#000000';
  }
  return computeAutoInColorFromTop();
}

function getOutColor(){
  return state?.theme?.colorOut
      || document.getElementById('dpb-color-out')?.dataset.selected
      || '#000000';
}

function applyAutoInColorIfNeeded(){
  if (state?.theme?.userPickedIn) return;
  const auto = computeAutoInColorFromTop();
  setColorGroupSelection('dpb-color-in', auto);
  state.theme = state.theme || {};
  state.theme.colorIn = auto;
}

(function wrapScheduleRedrawOnce(){
  if (window.__WRAPPED_SREDRAW) return;
  window.__WRAPPED_SREDRAW = true;
  const _orig = scheduleRedraw;
  window.scheduleRedraw = function(){
    try{ applyAutoInColorIfNeeded(); }catch(_){}
    _orig();
  };
})();

(function attachColorInPickHandler(){
  const group = document.getElementById('dpb-color-in');
  if (!group) return;
  group.querySelectorAll('button').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      state.theme = state.theme || {};
      state.theme.userPickedIn = true;
      state.theme.colorIn = btn.dataset.value || '#000000';
      if (typeof scheduleRedraw === 'function') scheduleRedraw();
    });
  });
})();

(function attachTopChangeAuto(){
  const el = document.getElementById('dpb-top-color') || document.getElementById('dpb-top');
  if (!el) return;
  el.addEventListener('change', ()=>{
    if (!state?.theme?.userPickedIn){
      applyAutoInColorIfNeeded();
      if (typeof scheduleRedraw === 'function') scheduleRedraw();
    }else{
      if (typeof scheduleRedraw === 'function') scheduleRedraw();
    }
  });
})();

window.__DPB_WM__ = window.__DPB_WM__ || {
  anchor: null,
  sc: 1,
  opts: {
    enabled:   true,
    opacity:   0.28,
    original:  false,
    black:     true,
    white:     false,
    scaleRatio: 0.40,
    debug:     false,
    autoColor: true
  },
  img: null,
  loading: false
};

function DPB__loadWMImage(onload){
  const S = window.__DPB_WM__;
  
  const topKey = document.getElementById('dpb-top-color')?.value || '';
  const colorObj = (state?.meta?.colors || []).find(c => c.key === topKey);
  const isWhiteboard = String(colorObj?.group || '').trim().toLowerCase() === 'whiteboard';
  
  // เก็บสถานะไว้ใน Object Global เพื่อให้ฟังก์ชันวาดนำไปใช้
  if (S) S.isWhiteboardMode = isWhiteboard;

  const targetLogoUrl = isWhiteboard 
    ? 'https://www.deskspace.in.th/wp-content/uploads/2026/02/Logo-DeskSpace-WhiteBoard.png' 
    : BRAND_LOGO_URL;

  if (S.img && S.img.complete && S.img.getAttribute('data-current-url') === targetLogoUrl) return S.img;
  if (S.loading && S.img.getAttribute('data-current-url') === targetLogoUrl) return S.img;

  const im = new Image();
  im.crossOrigin = 'anonymous';
  im.setAttribute('data-current-url', targetLogoUrl);
  
  im.onload = function(){ 
    S.loading = false; 
    if(typeof onload==='function') onload(); 
  };
  im.onerror = function(){ S.loading = false; };
  
  S.loading = true;
  im.src = targetLogoUrl;
  S.img = im;
  return im;
}

function DPB_setWatermarkAnchor(rect1, sc){
  try{
    if (!rect1 || !isFinite(rect1.x) || !isFinite(rect1.y) || !isFinite(rect1.w) || !isFinite(rect1.h)) return;
    window.__DPB_WM__.anchor = { x: rect1.x, y: rect1.y, w: rect1.w, h: rect1.h };
    window.__DPB_WM__.sc = +sc || 1;
  }catch(_){}
}

function DPB_setWatermarkOptions({ enabled, opacity, original, black, white, scaleRatio, debug } = {}){
  const S = window.__DPB_WM__.opts;
  if (typeof enabled    === 'boolean') S.enabled    = enabled;
  if (typeof opacity    === 'number')  S.opacity    = Math.max(0, Math.min(1, opacity));
  if (typeof scaleRatio === 'number')  S.scaleRatio = Math.max(0.05, Math.min(1, scaleRatio));
  if (typeof debug      === 'boolean') S.debug      = debug;
  const flags = { original, black, white };
  const asked = Object.keys(flags).filter(k => typeof flags[k] === 'boolean');
  if (asked.length){
    S.original = false; S.black = false; S.white = false;
    for (const k of asked){ if (flags[k]) { S[k] = true; } }
    if (!S.original && !S.black && !S.white) S.original = true;
  }
}

function DPB_toggleWatermark(on){ DPB_setWatermarkOptions({ enabled: !!on }); }

function DPB_debugWatermark(on){  DPB_setWatermarkOptions({ debug: !!on });  }

function DPB_applyWatermarkAutoColor(){
  try{
    if (!window.__DPB_WM__?.opts?.autoColor) return;
    const rawName = DPB_detectTopName();
    if (!rawName) return;
    const key = rawName.toLowerCase().replace(/\(.*?\)/g,'').replace(/\s+/g,' ').trim();
    let pick = WM_TOP_COLOR_MAP[key];
    if (!pick){
      for (const k of Object.keys(WM_TOP_COLOR_MAP)){
        if (key.includes(k)) { pick = WM_TOP_COLOR_MAP[k]; break; }
      }
    }
    if (!pick) return;
    if (pick === 'black'){
      DPB_setWatermarkOptions({ original:false, black:true,  white:false });
    }else{
      DPB_setWatermarkOptions({ original:false, black:false, white:true  });
    }
  }catch(_){}
}

function DPB_drawBrandWatermark_OnTop() {
    // 1. ตรวจสอบตัวแปร Global
    const WM = window.__DPB_WM__;
    if (!WM) return; 

    const A = WM.anchor;
    if (!WM?.opts?.enabled || !A) return;

    // 2. โหลดภาพ (ซึ่งตอนนี้ DPB__loadWMImage จะเลือกรูปตามประเภทท็อปให้เราแล้ว)
    const im = DPB__loadWMImage(() => {
        try { scheduleRedraw(); } catch (_) { }
    });
    if (!im || !im.complete) return;

    // 3. คำนวณขนาดภาพ
    const ratio = Math.max(0.05, Math.min(1, WM.opts.scaleRatio || 0.3));
    let w = A.w * ratio;
    let h = w * (im.naturalHeight / Math.max(1, im.naturalWidth));

    // ปรับขนาดถ้าสูงเกินพื้นที่
    if (h > A.h) {
        h = A.h;
        w = h * (im.naturalWidth / Math.max(1, im.naturalHeight));
    }

    const x = A.x + (A.w - w) / 2;
    const y = A.y + (A.h - h) / 2;

    // 4. จัดการเรื่องสีของ Logo (Black / White / Original)
    let srcCanvas = im;
    if (!WM.opts.original) {
        const off = document.createElement('canvas');
        off.width = Math.max(1, Math.round(w));
        off.height = Math.max(1, Math.round(h));
        const octx = off.getContext('2d');
        octx.drawImage(im, 0, 0, off.width, off.height);
        octx.globalCompositeOperation = 'source-atop';
        octx.fillStyle = WM.opts.white ? '#fff' : '#000';
        octx.fillRect(0, 0, off.width, off.height);
        octx.globalCompositeOperation = 'source-over';
        srcCanvas = off;
    }

    ctx.save();
    ctx.globalCompositeOperation = 'source-over';

    // --- [ส่วนที่แก้ไข: แยกความจางของ Whiteboard] ---
    // ดึงค่าปกติจากระบบมาก่อน (เช่น 0.8 หรือ 1.0)
    let finalOpacity = Math.max(0, Math.min(1, WM.opts.opacity)); 

    // ถ้าตรวจพบว่าเป็นโหมด Whiteboard ให้ใช้ค่าความจางที่ต้องการแยกต่างหาก
    if (WM.isWhiteboardMode) {
        finalOpacity = 0.8; // <--- แก้ไขความจางของ Logo Whiteboard ได้ที่นี่ (0.0 - 1.0)
    }
    
    ctx.globalAlpha = finalOpacity;
    // ----------------------------------------------

    ctx.drawImage(srcCanvas, x, y, w, h);

    // 5. ส่วน Debug สำหรับตรวจสอบ (ถ้าเปิดไว้)
    if (WM.opts.debug) {
        ctx.setLineDash([6, 4]);
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#ff2d55';
        ctx.strokeRect(A.x, A.y, A.w, A.h);
        ctx.strokeStyle = '#007aff';
        ctx.strokeRect(x, y, w, h);
        ctx.fillStyle = '#007aff';
        ctx.font = '500 12px Prompt,sans-serif';
        ctx.fillText(
            `WM ${Math.round(finalOpacity * 100)}% • ${WM.isWhiteboardMode ? 'Whiteboard-Mode' : 'Normal-Mode'}`,
            x, y - 6
        );
    }
    ctx.restore();
}

window.state = window.state || {};
state.ui = state.ui || {};

function dpbResetInfoDrawn(){ state.ui._infoDrawn = false; }

function dpbMarkInfoDrawn(){ state.ui._infoDrawn = true; }

function dpbWasInfoDrawn(){ return !!state.ui._infoDrawn; }

function dpb_drawStatusIndicatorDots() {

  if (typeof ctx === 'undefined' || !canvas) return;

  let typeCount = 0;


  if (window.state && window.state.selectedOptions) {
      // กรองเอาเฉพาะตัวเลือกที่มี count > 0 แล้วนับว่ามีกี่ "รายการ" (เช่น เลือกปลั๊ก(5) + ขาจอ(1) = 2 ชนิด)
      typeCount = Object.values(window.state.selectedOptions).filter(item => (item?.count || 0) > 0).length;
  }

  // เตรียมวาด
  ctx.save();
  ctx.setTransform(1, 0, 0, 1, 0, 0); 
  ctx.globalCompositeOperation = 'source-over';
  ctx.globalAlpha = 1.0;
  ctx.shadowBlur = 0; 

  const DOT_SIZE = 6; 
  const COLOR_LEFT  = '#eeede4'; 
  const COLOR_RIGHT = '#e4ecee'; 

  // 3. ใช้ตัวแปร typeCount ในการเช็คเงื่อนไข
  // หมายเหตุ: คุณอาจต้องปรับเลข 8 ลง หากชนิดของสินค้ามีไม่ถึง 8 ชนิด
  if (typeCount >= 8) { 
      ctx.fillStyle = COLOR_LEFT;
      ctx.fillRect(0, 0, DOT_SIZE, DOT_SIZE);
      
      ctx.fillStyle = COLOR_RIGHT;
      ctx.fillRect(canvas.width - DOT_SIZE, 0, DOT_SIZE, DOT_SIZE);

  } else if (typeCount > 0) {
      ctx.fillStyle = COLOR_LEFT;
      ctx.fillRect(0, 0, DOT_SIZE, DOT_SIZE);

  } else {
      ctx.fillStyle = COLOR_RIGHT;
      ctx.fillRect(canvas.width - DOT_SIZE, 0, DOT_SIZE, DOT_SIZE);
  }

  ctx.restore();
}

// ========================================================
// [CORRECTED] Helper Function: วาดกล่อง 3D (Standard Logic)
// ฟังก์ชันนี้รองรับการ Zoom และ Perspective ที่ถูกต้อง
// ========================================================
window.DPB_drawCuboid3Faces = function(ctx, imgs, baseP, w, h, d, projectFn) {
    // baseP: จุดเริ่มต้น {x, y} ในหน่วย CM (Logical Coordinate)
    // w, h, d: ขนาด กว้าง, สูง, ลึก ในหน่วย CM
    // projectFn: ฟังก์ชันแปลงจาก CM -> Pixel (Screen Coordinate)

    // 1. กำหนดจุดมุมทั้ง 4 บนพื้น (Logical Points - CM)
    const l_BL = { x: baseP.x,     y: baseP.y };
    const l_BR = { x: baseP.x + w, y: baseP.y };
    const l_FR = { x: baseP.x + w, y: baseP.y + d };
    const l_FL = { x: baseP.x,     y: baseP.y + d };

    // 2. แปลงจุดบนพื้นเป็นพิกัดหน้าจอ (Project to Screen Points - Floor)
    const p_BL_Floor = projectFn(l_BL.x, l_BL.y);
    const p_BR_Floor = projectFn(l_BR.x, l_BR.y);
    const p_FR_Floor = projectFn(l_FR.x, l_FR.y);
    const p_FL_Floor = projectFn(l_FL.x, l_FL.y);

    // 3. คำนวณความสูงที่จะยกขึ้น (Ceiling Points - Lift Logic)
    // 3.1 หาความกว้างจริงบนหน้าจอ (Pixel) ระหว่างจุดซ้ายล่างกับขวาล่าง
    const screenW = Math.hypot(p_BR_Floor.x - p_BL_Floor.x, p_BR_Floor.y - p_BL_Floor.y);
    
    // 3.2 คำนวณอัตราส่วน Scale (1 CM เท่ากับกี่ Pixel ณ ขณะนั้น)
    // ป้องกันการหารด้วย 0 โดยใช้ (w || 1)
    const scaleFactor = screenW / (w || 1); 
    
    // 3.3 แปลงความสูง (h) จาก CM เป็น Pixel ตาม Scale ที่ได้
    const liftPx = h * scaleFactor; 

    // ฟังก์ชันย่อยสำหรับยกจุดขึ้นตามแกน Y (Screen Y)
    const toTop = (p) => ({ x: p.x, y: p.y - liftPx });
    
    const p_BL_Top = toTop(p_BL_Floor);
    const p_BR_Top = toTop(p_BR_Floor);
    const p_FR_Top = toTop(p_FR_Floor);
    const p_FL_Top = toTop(p_FL_Floor);

    // 4. วาดพื้นผิว (Draw Faces) เรียงลำดับจากหลังมาหน้า (Painters Algorithm)
    // หมายเหตุ: ลำดับการวาดอาจต้องปรับตามมุมมอง แต่โดยทั่วไปสำหรับ Top-Down View ลำดับนี้ใช้ได้ครับ
    
    // ด้านขวา (Right Face)
    if (imgs[1]) {
        window.DPB_drawTexturedQuad(ctx, imgs[1], p_FR_Top, p_BR_Top, p_BR_Floor, p_FR_Floor); 
    }
    
    // ด้านหน้า (Front Face)
    if (imgs[0]) {
        window.DPB_drawTexturedQuad(ctx, imgs[0], p_FL_Top, p_FR_Top, p_FR_Floor, p_FL_Floor); 
    }
    
    // ด้านบน (Top Face)
    if (imgs[2]) {
        window.DPB_drawTexturedQuad(ctx, imgs[2], p_FL_Top, p_FR_Top, p_BR_Top, p_BL_Top);       
    }
};

window.drawOptionsIn3D = function(ctx, L, W, projectFn) {

    const DEBUG_MODE = false; 

    // [CONFIG] เป็น 1.0 ทั้งหมด (ปิดการบิดภายใน)
    const P_STRENGTH_X = 1.0; 
    const P_STRENGTH_Y = 1.0; 

    // ฟังก์ชันนี้จะคืนค่าเดิมกลับไป (Linear)
    const mapToVisual = (val, maxVal, pStrength) => {
        return val; 
    };

    try {
        const byId = (id) => document.getElementById(id);
        const modelType = (byId('dpb-type')?.value || 'custom').toLowerCase();
        const isLDesk = (modelType === 'l2' || modelType === 'l3');
        const side = (byId('dpb-aside')?.value || 'right').toLowerCase();

        const ML = +(byId('dpb-ml')?.value || 190);
        const MW = +(byId('dpb-mw')?.value || 60);
        const AL = +(byId('dpb-al')?.value || 60);
        const AW = +(byId('dpb-aw')?.value || 120);

        let rectMain, rectArm;
        if (!isLDesk) {
            rectMain = { x: 0, y: 0, w: ML, h: MW };
            rectArm = null;
        } else {
            if (side === 'right') {
                rectMain = { x: 0, y: 0, w: ML, h: MW }; 
                rectArm  = { x: ML - AL, y: 0, w: AL, h: AW };
            } else {
                rectArm  = { x: 0, y: 0, w: AL, h: AW };
                rectMain = { x: 0, y: 0, w: ML, h: MW };
            }
        }

        if (DEBUG_MODE) {
            const drawDebugRect = (r, color) => {
                if(!r) return;
                const p1 = projectFn(r.x, r.y);
                const p2 = projectFn(r.x + r.w, r.y);
                const p3 = projectFn(r.x + r.w, r.y + r.h);
                const p4 = projectFn(r.x, r.y + r.h);
                
                ctx.save();
                ctx.strokeStyle = color; ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
                ctx.lineTo(p3.x, p3.y); ctx.lineTo(p4.x, p4.y);
                ctx.closePath(); ctx.stroke();
                ctx.fillStyle = color; ctx.font = "20px Arial";
                ctx.fillText(color === 'lime' ? "MAIN" : "ARM", p1.x + 10, p1.y + 30);
                ctx.restore();
            };
            drawDebugRect(rectMain, 'lime');
            if(rectArm) drawDebugRect(rectArm, 'red');
            
            // ========================================================
            // [UPDATED] Draw Ruler Function (Fix Perspective Mismatch)
            // ========================================================
            const drawRuler = (r, type, otherRect = null) => {
                if (!r) return;
                const OFFSET_X = 0; const OFFSET_Y = 0;
                const GLOBAL_STR_X = (typeof P_STRENGTH_X !== 'undefined') ? P_STRENGTH_X : 1.0;
                const GLOBAL_STR_Y = (typeof P_STRENGTH_Y !== 'undefined') ? P_STRENGTH_Y : 1.0;

                const drawEdgeTick = (val, maxVal, edge) => {
                    let currentStrength = (edge === 'top' || edge === 'bottom') ? GLOBAL_STR_X : GLOBAL_STR_Y;
                    let drawPos = 0;

                    // [FIX START] Logic L-Right Arm
                    if (type === 'arm' && side === 'right' && (edge === 'top' || edge === 'bottom')) {
                        const startGlobal = ML - AL;
                        const currentGlobal = startGlobal + val; 
                        const vStart = mapToVisual(startGlobal, ML, currentStrength);
                        const vCurrent = mapToVisual(currentGlobal, ML, currentStrength);
                        drawPos = vCurrent - vStart;
                    } else {
                        let ratio = val / maxVal;
                        let visualRatio = Math.pow(ratio, currentStrength);
                        drawPos = visualRatio * maxVal;
                    }
                    // [FIX END]

                    let lx = r.x + OFFSET_X; let ly = r.y + OFFSET_Y;
                    let dirX = 0; let dirY = 0;

                    if (edge === 'top') { lx += drawPos; ly += 0; dirY = -1; } 
                    else if (edge === 'bottom') { lx += drawPos; ly += r.h; dirY = 1; } 
                    else if (edge === 'left') { lx += 0; ly += drawPos; dirX = -1; } 
                    else if (edge === 'right') { lx += r.w; ly += drawPos; dirX = 1; }

                    if (type === 'main' && otherRect) {
                        if (edge !== 'top') {
                            const inX = (lx >= otherRect.x && lx <= otherRect.x + otherRect.w);
                            const inY = (ly >= otherRect.y && ly <= otherRect.y + otherRect.h);
                            if (inX && inY) return;
                        }
                    }

                    let lx2 = lx + (0 * dirX); let ly2 = ly + (0 * dirY);
                    const isTen = (val % 10 === 0);
                    const isCenter = (Math.abs(val - maxVal / 2) < 0.1);
                    const len = isCenter ? 14 : (isTen ? 8 : 4);

                    lx2 += (len * dirX); ly2 += (len * dirY);

                    const p1 = projectFn(lx, ly); const p2 = projectFn(lx2, ly2);

                    ctx.save(); ctx.beginPath();
                    ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
                    if (isCenter) { ctx.strokeStyle = '#D32F2F'; ctx.lineWidth = 2.5; } 
                    else if (isTen) { ctx.strokeStyle = 'rgba(0,0,0,1.0)'; ctx.lineWidth = 1.5; } 
                    else { ctx.strokeStyle = 'rgba(0,0,0,0.4)'; ctx.lineWidth = 0.8; }
                    ctx.stroke();

                    if (isTen || isCenter) {
                        const txt = isCenter ? "CNTR" : Math.round(val);
                        const dx = p2.x - p1.x; const dy = p2.y - p1.y;
                        const angle = Math.atan2(dy, dx); const textDist = 14; 
                        ctx.fillStyle = isCenter ? '#D32F2F' : '#000000';
                        ctx.font = "bold 15px Arial";
                        ctx.textAlign = "center"; ctx.textBaseline = "middle";
                        ctx.fillText(txt, p2.x + Math.cos(angle)*textDist, p2.y + Math.sin(angle)*textDist);
                    }
                    ctx.restore();
                };

                for (let i = 0; i <= r.w; i += 2) {
                    if (type === 'main') { drawEdgeTick(i, r.w, 'top'); drawEdgeTick(i, r.w, 'bottom'); } 
                    else { drawEdgeTick(i, r.w, 'bottom'); }
                }
                for (let j = 0; j <= r.h; j += 2) {
                    if (type === 'main') { drawEdgeTick(j, r.h, 'left'); drawEdgeTick(j, r.h, 'right'); } 
                    else {
                        let isArmRight = (typeof side !== 'undefined' && side === 'right');
                        if (isArmRight) drawEdgeTick(j, r.h, 'right'); else drawEdgeTick(j, r.h, 'left'); 
                    }
                }
            };
            drawRuler(rectMain, 'main', rectArm); 
            if (rectArm) drawRuler(rectArm, 'arm');
        }

        // [3] Loop Options
        if (!window.state || !window.state.selectedOptions) return;
        const opts = window.state.selectedOptions;
        const optConfig = window.state.optConfig || {};
        const metaOpts = window.state.meta.options || [];
        const TRACK_KEY = 'power_track';

        let drawQueue = [];
        let trackInfo = null;
        let sockets = [];

        Object.keys(opts).forEach(key => {
            const sel = opts[key];
            if (!sel || !sel.count) return;
            const configArr = optConfig[key] || [];
            const isSocket = (key === 'power_socket' || key === 'usb_charger');

            for (let i = 0; i < sel.count; i++) {
                const cfg = configArr[i] || {};
                const opData = metaOpts.find(o => o.key === key);
                const isCircle = (opData && opData.type === 'hole_circle');

                let vIndex = 0;
                if (typeof cfg.variantIndex !== 'undefined') vIndex = Number(cfg.variantIndex);
                else if (cfg.variant && opData && opData.variants) {
                    const foundIdx = opData.variants.findIndex(v => v.name === cfg.variant);
                    if (foundIdx !== -1) vIndex = foundIdx;
                }
                
                let imgUrlStr = null;
                if (isSocket && opData?.rawImageUrl3d?.includes(',')) imgUrlStr = opData.rawImageUrl3d;
                else {
                    const variantObj = (opData?.variants) ? opData.variants[vIndex] : null;
                    imgUrlStr = variantObj?.imageUrl3d || opData?.imageUrl3d || variantObj?.imageUrl || opData?.imageUrl;
                }

                let imgUrls = [];
                if (imgUrlStr && typeof imgUrlStr === 'string') imgUrls = imgUrlStr.split(',').map(s => s.trim());
                if (imgUrls.length === 0 && !isSocket) continue;

                let mainImgUrl = imgUrls[0];
                if (!window.DPB_IMG_CACHE) window.DPB_IMG_CACHE = {};
                if (!window.DPB_IMG_CACHE[mainImgUrl]) {
                    const im = new Image(); im.crossOrigin = "Anonymous"; im.src = mainImgUrl;
                    im.onload = () => { if (window.drawDesk3D) window.drawDesk3D(); };
                    window.DPB_IMG_CACHE[mainImgUrl] = im;
                }
                let mainImgObj = window.DPB_IMG_CACHE[mainImgUrl];
                
                let imgObjs = imgUrls.map(url => {
                    if (!window.DPB_IMG_CACHE[url]) {
                        const im = new Image(); im.crossOrigin = "Anonymous"; im.src = url;
                        window.DPB_IMG_CACHE[url] = im;
                    }
                    return window.DPB_IMG_CACHE[url];
                });

                // --- Base Dimensions ---
                let baseW = Number(cfg.w) || 10;
                let baseH = Number(cfg.h) || 10;
                const fromRaw = String(cfg.from || '').toLowerCase();
                const placeRaw = String(cfg.place || '').toLowerCase();
                const posMode = (cfg.pos || 'main').toLowerCase();
                let offX = Number(cfg.offsetX || 0);
                let offY = Number(cfg.offsetY || 0);
                
                if (key === TRACK_KEY) {
                    let pMode = 'center';
                    if (['left', 'ซ้าย'].some(s => placeRaw.includes(s))) pMode = 'left';
                    else if (['right', 'ขวา'].some(s => placeRaw.includes(s))) pMode = 'right';
                    const isWide = baseW >= 85; 
                    const POS_ADJ  = isWide ? { 'left': 0.0, 'center': 0.0, 'right': 0.0 } : { 'left': 0.0, 'center': 0.0, 'right': 0.0 };
                    offX += POS_ADJ[pMode];
                }

                const isRotated = !!cfg.rotate;
                let rawW = isCircle ? baseW : (isRotated ? baseH : baseW);
                let rawH = isCircle ? baseW : (isRotated ? baseW : baseH);

                // --- Zone Selection ---
                let boxUse = rectMain;
                // ตรวจสอบทิศทางวาง (Placement)
                let isRightSide = ['right', 'ขวา', 'ด้านขวา'].some(s => placeRaw.includes(s));
                let isLeftSide = ['left', 'ซ้าย', 'ด้านซ้าย'].some(s => placeRaw.includes(s));

                if (isLDesk) {
                    let useArm = false;
                    if (posMode === 'arm') {
                        useArm = true;
                        // ถ้าวางบน Arm จะบังคับทิศทางการหมุนตาม Side ของโต๊ะ
                        if (side === 'right') { isRightSide = true; isLeftSide = false; }
                        else { isLeftSide = true; isRightSide = false; }
                    }
                    // ถ้า L-Right และวางขวา -> ลง Arm
                    if (side === 'right' && isRightSide) useArm = true;
                    // ถ้า L-Left และวางซ้าย -> ลง Arm
                    if (side === 'left' && isLeftSide) useArm = true;

                    if (useArm && rectArm) boxUse = rectArm;
                }

                // --- Linear Calculation (ตำแหน่ง) ---
                let linearL = 0; let linearT = 0; 
                // ใช้ค่าจาก Config (placeRaw) โดยตรงเพื่อคำนวณตำแหน่ง
                if (['left', 'ซ้าย'].some(s => placeRaw.includes(s))) {
                    linearL = offX;
                } else if (['center', 'ตรงกลาง', 'กลาง'].some(s => placeRaw.includes(s))) {
                    linearL = (boxUse.w - rawW) / 2 + offX;
                } else { 
                    linearL = boxUse.w - rawW - offX;
                }

                if (['top', 'บน'].some(s => fromRaw.includes(s))) {
                    linearT = offY;
                } else if (['center', 'ตรงกลาง'].some(s => fromRaw.includes(s))) {
                    linearT = (boxUse.h - rawH) / 2 + offY; 
                } else {
                    linearT = boxUse.h - rawH - offY;
                }

                let linearR = linearL + rawW;
                let linearB = linearT + rawH;

                const visL = mapToVisual(linearL, boxUse.w, P_STRENGTH_X);
                const visR = mapToVisual(linearR, boxUse.w, P_STRENGTH_X);
                const visT = mapToVisual(linearT, boxUse.h, P_STRENGTH_Y);
                const visB = mapToVisual(linearB, boxUse.h, P_STRENGTH_Y);

                let localX = visL; let localY = visT;
                let logicW = visR - visL; let logicH = visB - visT; 

                const globalX = boxUse.x + localX;
                const globalY = boxUse.y + localY;

                const pTL = projectFn(globalX, globalY);
                const pTR = projectFn(globalX + logicW, globalY);
                const pBR = projectFn(globalX + logicW, globalY + logicH);
                const pBL = projectFn(globalX, globalY + logicH);
                
                const pCenter = projectFn(globalX + logicW/2, globalY + logicH/2);
                const screenW = Math.hypot(pTR.x - pTL.x, pTR.y - pTL.y);
                const scaleFactor = screenW / (logicW || 1); 
                const BASE_THICK = 0.3; 
                const liftY = scaleFactor * BASE_THICK * (isRotated ? 1.0 : 1.2);

                // ========================================================
                // [UPDATED v5.2] Sliding Socket Lid (Vector Extension Fix)
                // ========================================================
                if (key === 'sliding_socket') {
                    const LID_URLS = { white: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Sliding-Socket_White1.webp', black: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Sliding-Socket_Black1.webp' };
                    let lidUrl = LID_URLS.black; const vName = (cfg.variant || '').toLowerCase();
                    if (vName.includes('white') || vName.includes('ขาว')) lidUrl = LID_URLS.white;
                    if (!window.DPB_IMG_CACHE[lidUrl]) { const im = new Image(); im.crossOrigin = "Anonymous"; im.src = lidUrl; im.onload = () => { if (window.drawDesk3D) window.drawDesk3D(); }; window.DPB_IMG_CACHE[lidUrl] = im; }
                    const lidImg = window.DPB_IMG_CACHE[lidUrl];

                    if (lidImg && lidImg.complete && lidImg.naturalWidth > 0) {
                        const imgRatio = lidImg.naturalHeight / lidImg.naturalWidth;
                        const refDim = isRotated ? rawH : rawW;
                        const lidLinearSize = refDim * imgRatio; 
                        let pLidTL, pLidTR, pLidBR, pLidBL;

                        if (!isRotated) {
                            // --- ปกติ: ฝาเปิดขึ้นด้านบน ---
                            pLidBL = { x: pTL.x, y: pTL.y }; pLidBR = { x: pTR.x, y: pTR.y };
                            const lidLinearTop = linearT - lidLinearSize;
                            const gLidTopY = boxUse.y + mapToVisual(lidLinearTop, boxUse.h, P_STRENGTH_Y);
                            pLidTL = projectFn(boxUse.x + visL, gLidTopY); pLidTR = projectFn(boxUse.x + visR, gLidTopY);
                        } else {
                            // --- หมุน: ใช้ Vector Extension เพื่อความแม่นยำ ---
                            // คำนวณอัตราส่วนความยาวฝาต่อความกว้างเต้า (แกนสไลด์)
                            const linearRatio = lidLinearSize / rawW;

                            if (isRightSide) {
                                // Right Side: ยืด Vector ออกไปทางขวา (จาก TL->TR และ BL->BR)
                                // Snap anchors: ฝาซ้าย = เต้าขวา
                                pLidTL = { x: pTR.x, y: pTR.y }; 
                                pLidBL = { x: pBR.x, y: pBR.y }; 

                                // Vector Top & Bottom
                                const vTopX = pTR.x - pTL.x; const vTopY = pTR.y - pTL.y;
                                const vBotX = pBR.x - pBL.x; const vBotY = pBR.y - pBL.y;

                                // Extend outwards
                                pLidTR = { x: pTR.x + vTopX * linearRatio, y: pTR.y + vTopY * linearRatio };
                                pLidBR = { x: pBR.x + vBotX * linearRatio, y: pBR.y + vBotY * linearRatio };

                            } else {
                                // Left Side: ยืด Vector ย้อนไปทางซ้าย (จาก TL<-TR และ BL<-BR)
                                // Snap anchors: ฝาขวา = เต้าซ้าย
                                pLidTR = { x: pTL.x, y: pTL.y }; 
                                pLidBR = { x: pBL.x, y: pBL.y }; 

                                // Vectors (ทิศทางเดิม TL->TR)
                                const vTopX = pTR.x - pTL.x; const vTopY = pTR.y - pTL.y;
                                const vBotX = pBR.x - pBL.x; const vBotY = pBR.y - pBL.y;

                                // Extend backwards (ลบ vector)
                                pLidTL = { x: pTL.x - vTopX * linearRatio, y: pTL.y - vTopY * linearRatio };
                                pLidBL = { x: pBL.x - vBotX * linearRatio, y: pBL.y - vBotY * linearRatio };
                            }
                        }
                        drawQueue.push({ 
                            imgObj: lidImg, pTL: pLidTL, pTR: pLidTR, pBR: pLidBR, pBL: pLidBL, 
                            liftY, isRotated, placeRaw, 
                            zIndex: pCenter.y, // [FIX] ใช้ Z เดียวกับเต้า ไม่ -1
                            isCircle: false 
                        });
                    }
                }

                // [IMPORTANT] Pass isRightSide / isLeftSide for Rotation Logic
                const drawData = {
                    imgObj: mainImgObj,
                    pTL, pTR, pBR, pBL, liftY,
                    isRotated, placeRaw,
                    zIndex: pCenter.y, 
                    isCircle,
                    isRightSide, isLeftSide 
                };

                if (key === TRACK_KEY) {
                    trackInfo = { globalX, globalY, logicW, logicH, zIndex: pCenter.y, placeRaw, isRotated, isRightSide, isLeftSide };
                    drawQueue.push(drawData);
                } else if (isSocket && (opts[TRACK_KEY] && opts[TRACK_KEY].count > 0)) {
                    let sW = 4.2, sL = 3.4, sH = 8.0; if (key === 'usb_charger') { sW = 4.5; sL = 3.2; sH = 6.6; }
                    sockets.push({ key, imgObjs, dims: { w: sW, l: sL, h: sH } });
                } else {
                    drawQueue.push(drawData);
                }
            } 
        });

        // ========================================================
        // [UPDATED] Power Track Logic (Rotation + Side Direction)
        // ========================================================
        if (trackInfo && sockets.length > 0) {
             const isWide = (trackInfo.isRotated ? trackInfo.logicH : trackInfo.logicW) >= 85; 
             const maxSockets = isWide ? 8 : 5; if (sockets.length > maxSockets) sockets = sockets.slice(0, maxSockets);
             const GAP = 3.0; let totalSocketLen = 0;
             sockets.forEach((s, i) => { totalSocketLen += s.dims.w; if (i < sockets.length - 1) totalSocketLen += GAP; });
             const trackCenterX = trackInfo.globalX + (trackInfo.logicW / 2);
             const trackCenterY = trackInfo.globalY + (trackInfo.logicH / 2);
             let currentPos = trackInfo.isRotated ? (trackCenterY - (totalSocketLen / 2)) : (trackCenterX - (totalSocketLen / 2));

             sockets.forEach((s, index) => {
                 let socketX, socketY, socketW, socketL; const MANUAL_OFFSET = -0.65; 
                 let drawImgs = [...s.imgObjs];
                 if (trackInfo.isRotated) {
                     socketX = trackCenterX - (s.dims.l / 2) + MANUAL_OFFSET; socketY = currentPos;
                     socketW = s.dims.l; socketL = s.dims.w; 
                     drawImgs[0] = s.imgObjs[1]; drawImgs[1] = s.imgObjs[0];
                 } else {
                     socketX = currentPos; socketY = trackCenterY - (s.dims.l / 2) + MANUAL_OFFSET;
                     socketW = s.dims.w; socketL = s.dims.l; 
                 }
                 
                 drawQueue.push({
                    zIndex: trackInfo.zIndex + 10 + index, 
                    customDraw: (ctx) => {
                        const baseP = { x: socketX, y: socketY }; const w = socketW; const d = socketL; const h = s.dims.h;
                        const l_BL = { x: baseP.x, y: baseP.y }; const l_BR = { x: baseP.x + w, y: baseP.y };
                        const l_FR = { x: baseP.x + w, y: baseP.y + d }; const l_FL = { x: baseP.x, y: baseP.y + d };
                        const p_BL_Floor = projectFn(l_BL.x, l_BL.y); const p_BR_Floor = projectFn(l_BR.x, l_BR.y);
                        const p_FR_Floor = projectFn(l_FR.x, l_FR.y); const p_FL_Floor = projectFn(l_FL.x, l_FL.y);
                        const screenW = Math.hypot(p_BR_Floor.x - p_BL_Floor.x, p_BR_Floor.y - p_BL_Floor.y);
                        const scaleFactor = screenW / (w || 1); const liftPx = h * scaleFactor; 
                        const toTop = (p) => ({ x: p.x, y: p.y - liftPx });
                        const p_BL_Top = toTop(p_BL_Floor); const p_BR_Top = toTop(p_BR_Floor);
                        const p_FR_Top = toTop(p_FR_Floor); const p_FL_Top = toTop(p_FL_Floor);

                        if (drawImgs[1]) window.DPB_drawTexturedQuad(ctx, drawImgs[1], p_FR_Top, p_BR_Top, p_BR_Floor, p_FR_Floor); 
                        if (drawImgs[0]) window.DPB_drawTexturedQuad(ctx, drawImgs[0], p_FL_Top, p_FR_Top, p_FR_Floor, p_FL_Floor); 
                        if (drawImgs[2]) {
                            if (trackInfo.isRotated) {
                                if (trackInfo.isRightSide) window.DPB_drawTexturedQuad(ctx, drawImgs[2], p_FR_Top, p_BR_Top, p_BL_Top, p_FL_Top); // CW
                                else window.DPB_drawTexturedQuad(ctx, drawImgs[2], p_BL_Top, p_FL_Top, p_FR_Top, p_BR_Top); // CCW
                            } else { window.DPB_drawTexturedQuad(ctx, drawImgs[2], p_FL_Top, p_FR_Top, p_BR_Top, p_BL_Top); }
                        }
                    }
                });
                currentPos += s.dims.w + GAP; 
            });
        }

        // --- Generic Draw Loop ---
        drawQueue.sort((a, b) => a.zIndex - b.zIndex);
        drawQueue.forEach(item => {
             if (item.customDraw) { item.customDraw(ctx); } 
             else {
                const { imgObj, pTL, pTR, pBR, pBL, liftY, isRotated, placeRaw, isCircle, isRightSide, isLeftSide } = item;
                const SIDE_BRIGHTNESS = 0.9;
                
                // [FIXED] Universal Rotation Logic
                const getRotatedPoints = (p1, p2, p3, p4) => {
                    if (!isRotated) return [p1, p2, p3, p4];
                    if (isRightSide) return [p2, p3, p4, p1]; // CW
                    if (isLeftSide) return [p4, p1, p2, p3]; // CCW
                    return [p4, p1, p2, p3]; 
                };

                if (imgObj && imgObj.complete && imgObj.naturalWidth > 0 && typeof window.DPB_drawTexturedQuad === 'function') {
                    const steps = Math.ceil(liftY);
                    ctx.save(); ctx.filter = `brightness(${SIDE_BRIGHTNESS})`;
                    for (let k = 0; k < steps; k++) {
                        const curY = k; const vanishingX = (pTL.x + pTR.x) / 2; const centerX = 500; 
                        const dist = vanishingX - centerX; const skewFactor = (dist === 0) ? 0.2 : (dist * 0.002); const shiftX = k * -skewFactor;
                        const layer = [ { x: pTL.x + shiftX, y: pTL.y - curY }, { x: pTR.x + shiftX, y: pTR.y - curY }, { x: pBR.x + shiftX, y: pBR.y - curY }, { x: pBL.x + shiftX, y: pBL.y - curY } ];
                        const quad = getRotatedPoints(...layer);
                        window.DPB_drawTexturedQuad(ctx, imgObj, quad[0], quad[1], quad[2], quad[3], isCircle);
                    }
                    ctx.restore();
                    const vanishingX = (pTL.x + pTR.x) / 2; const centerX = 500; 
                    const dist = vanishingX - centerX; const skewFactor = (dist === 0) ? 0.2 : (dist * 0.002); const topShiftX = steps * -skewFactor;
                    const topPoints = [ { x: pTL.x + topShiftX, y: pTL.y - liftY }, { x: pTR.x + topShiftX, y: pTR.y - liftY }, { x: pBR.x + topShiftX, y: pBR.y - liftY }, { x: pBL.x + topShiftX, y: pBL.y - liftY } ];
                    const topQuad = getRotatedPoints(...topPoints);
                    window.DPB_drawTexturedQuad(ctx, imgObj, topQuad[0], topQuad[1], topQuad[2], topQuad[3], isCircle);
                }
            }
        });
    } catch(e) { console.error("DPB 3D Draw Error:", e); }
};

window.getParamsForLegs = function() {
    const byId = (id) => document.getElementById(id);
    let model = (byId('dpb-type')?.value || 'custom').toLowerCase();
    
    // [NEW] ดึงค่า Side (ทิศทางโต๊ะ)
    const sideRaw = (byId('dpb-aside')?.value || 'right').toLowerCase(); // 'left' or 'right'

    if (!LEG_3D_ASSETS[model]) model = 'custom';

    // ... (Logic หา legTypeRaw เหมือนเดิม) ...
    let legTypeRaw = 'square_white'; 
    const legInput = byId('dpb-legs'); 
    if (legInput && legInput.value) {
        legTypeRaw = legInput.value;
    } else {
        const activeTile = document.querySelector('#dpb-legs-tiles .active');
        if (activeTile && activeTile.dataset.value) legTypeRaw = activeTile.dataset.value;
    }

    let finalLegKey = legTypeRaw;
    if (model === 'single') {
        if (legTypeRaw.includes('black')) finalLegKey = 'black';
        else finalLegKey = 'white';
    } else {
        if (!finalLegKey.includes('_')) {
             if (finalLegKey.includes('white')) finalLegKey = 'square_white';
             else if (finalLegKey.includes('black')) finalLegKey = 'square_black';
             else finalLegKey = 'square_white';
        }
    }

    // [UPDATED] Logic การเลือก Assets
    let assets = LEG_3D_ASSETS[model]?.[finalLegKey];

    // กรณีที่เป็น L2 หรือ L3 เราต้องเจาะจงลงไปเลือก sub-object ตาม Side
    if (assets && (model === 'l2' || model === 'l3')) {
        // ถ้ามี key 'right' หรือ 'left' อยู่ข้างใน ให้เลือกตาม sideRaw
        if (assets[sideRaw]) {
            assets = assets[sideRaw];
        } else {
            // Fallback กรณีหาไม่เจอ ให้ใช้ตัวแรกที่มี
            assets = assets['right'] || assets;
        }
    }

    // Fallbacks (เหมือนเดิม)
    if (!assets) {
        const firstKey = Object.keys(LEG_3D_ASSETS[model])[0];
        assets = LEG_3D_ASSETS[model][firstKey];
        if (model === 'l2' || model === 'l3') assets = assets['right'] || assets; // Fallback side
    }
    if (!assets) assets = LEG_3D_ASSETS['custom']['square_white'];

    return { model, assets };
};

// ============================================================================
// 1. Core Function: วาด Texture Quad (พื้นฐาน)
// ============================================================================
window.DPB_drawTexturedQuad = function(ctx, img, pTL, pTR, pBR, pBL, isCircle = false) {
    if (!img || !img.complete || img.naturalWidth === 0) return;
    const iw = img.naturalWidth;
    const ih = img.naturalHeight;
    ctx.save();
    ctx.globalCompositeOperation = 'source-over'; 
    ctx.globalAlpha = 1.0;
    const dx = pTL.x;
    const dy = pTL.y;

    const m11 = (pTR.x - pTL.x) / iw;
    const m12 = (pTR.y - pTL.y) / iw;
    const m21 = (pBL.x - pTL.x) / ih;
    const m22 = (pBL.y - pTL.y) / ih;

    ctx.transform(m11, m12, m21, m22, dx, dy);

    if (isCircle) {
        ctx.beginPath();
        ctx.arc(iw / 2, ih / 2, iw / 2, 0, Math.PI * 2);
        ctx.clip();
    }

    ctx.drawImage(img, 0, 0);
    ctx.restore();
};



// ============================================================================
// [MATH HELPER] ฟังก์ชันช่วยคำนวณ Geometry 2D เพื่อ Project เป็น 3D
// ============================================================================
const Math3DHelper = {
    // คำนวณระยะห่างระหว่างจุด 2 จุด
    dist: (p1, p2) => Math.hypot(p2.x - p1.x, p2.y - p1.y),

    // จำลอง logic ctx.arcTo แต่ส่งคืนเป็น array ของจุด 3D แทนการวาดเลย
    // current2D: จุดปัจจุบันในระนาบ 2D (Logical)
    // p1, p2: จุด control points (มุมแหลม) และจุดปลายทาง
    // radius: รัศมี
    // projectFn: ฟังก์ชันแปลง 2D -> 3D
    calculateArcTo3D: (current2D, p1, p2, radius, projectFn) => {
        const p0 = current2D;
        
        // 1. Vector คำนวณทิศทาง
        const v01 = { x: p0.x - p1.x, y: p0.y - p1.y };
        const v21 = { x: p2.x - p1.x, y: p2.y - p1.y };
        const len01 = Math.hypot(v01.x, v01.y);
        const len21 = Math.hypot(v21.x, v21.y);

        // Normalize
        const d01 = { x: v01.x / len01, y: v01.y / len01 };
        const d21 = { x: v21.x / len21, y: v21.y / len21 };

        // มุมระหว่างเส้น
        const angle = Math.acos(d01.x * d21.x + d01.y * d21.y);
        const halfTan = Math.tan((Math.PI - angle) / 2);
        
        // ระยะจากมุม (p1) ไปยังจุดสัมผัส (Tangent Start/End)
        let segLen = radius / halfTan;

        // Safety: ถ้ารัศมีใหญ่เกินความยาวเส้น ให้ลดลงมา
        if (segLen > len01) segLen = len01;
        if (segLen > len21) segLen = len21;

        // จุดสัมผัส (Tangent Points) ใน 2D
        const tStart = { x: p1.x + d01.x * segLen, y: p1.y + d01.y * segLen };
        const tEnd   = { x: p1.x + d21.x * segLen, y: p1.y + d21.y * segLen };

        // จุดศูนย์กลางวงกลม (หาจากการ Cross vector หรือ Perpendicular)
        // เพื่อความง่าย เราใช้วิธี Interpolate มุมเอา
        // หามุมของเส้นสัมผัสเทียบกับแกนโลก
        const angleStart = Math.atan2(tStart.y - p1.y, tStart.x - p1.x); // มุมเส้นขาเข้า
        const angleEnd   = Math.atan2(tEnd.y - p1.y, tEnd.x - p1.x);     // มุมเส้นขาออก

        // คำนวณจุดศูนย์กลางโค้ง (Center)
        // ข้ามการหา Center ที่แม่นยำ แล้วใช้ Quadratic Bezier หรือ Subdivision
        // ในที่นี้ใช้ Subdivision (ซอยเส้น) เพื่อความเนียนใน 3D
        
        const segments = 6; // ความละเอียดของมุมโค้ง
        const points3D = [];

        // 1. เส้นตรงจากจุดปัจจุบัน ไปยังจุดเริ่มโค้ง
        points3D.push({ type: 'line', p3d: projectFn(tStart.x, tStart.y) });

        // 2. ส่วนโค้ง (Curve)
        // เราใช้ Quadratic Bezier ใน 2D แล้ว Project จุด Control ไป 3D
        // หรือคำนวณจุดบนเส้นโค้งจริงๆ
        // เพื่อความแม่นยำสูงสุดใน L-Shape ใช้ Quadratic Bezier โดยใช้ p1 เป็น Control Point
        // *หมายเหตุ* arcTo จริงๆ คือส่วนหนึ่งของวงกลม แต่ Quadratic คือ Parabola
        // แต่ในงานเฟอร์นิเจอร์ 3D Web การใช้ Quadratic Curve โดยใช้มุมเป็น Control Point 
        // ให้ผลลัพธ์ที่สวยและเร็วกว่าการคำนวณวงกลมเป๊ะๆ
        
        const cp = projectFn(p1.x, p1.y); // Control Point (Corner)
        const ep = projectFn(tEnd.x, tEnd.y); // End Point

        points3D.push({ type: 'curve', cp: cp, end: ep });

        return {
            points: points3D,
            newCurrent2D: tEnd // คืนค่าจุดปลาย เพื่อใช้เป็นจุดเริ่มของเส้นถัดไป
        };
    }
};

window.createDynamicDeskPath = function(L, W, radii, options = {}) {
    const path = new Path2D();
    const orgX = 616.9; 
    const orgY = 153.9;
    
    const vL_x = 4.5855, vL_y = 2.469; 
    const vW_start_x = -7.000, vW_start_y = 1.000;
    const vW_end_x   = -8.453, vW_end_y   = 2.865;
    
    // [CONFIG] ค่าอ้างอิงและค่าความแรง Perspective
    const REF_L = 200.0;
    const REF_W = 100.0; // ค่าอ้างอิงความลึก (Standard Depth)
    
    const P_DEPTH = 0.0005; 
    const P_STRENGTH_X = 1.20; // บิดแกน X (ซ้ายขวา)
    const P_STRENGTH_Y = 1.10; // บิดแกน Y (บนล่าง) <-- เพิ่มตามที่ขอ

    // Projection Function (Full Warp X & Y)
    const project = (l, w) => {
        // --- 1. Warp Length (X) ---
        let ratioL = l / REF_L;
        let warpedRatioL = Math.pow(Math.abs(ratioL), P_STRENGTH_X);
        if (ratioL < 0) warpedRatioL = -warpedRatioL;
        let warpedL = warpedRatioL * REF_L;

        // --- 2. Warp Width/Depth (Y) ---
        // บิดให้ด้านบน(0) ถี่กว่าด้านล่าง(W)
        let ratioW = w / REF_W;
        let warpedRatioW = Math.pow(Math.abs(ratioW), P_STRENGTH_Y);
        if (ratioW < 0) warpedRatioW = -warpedRatioW;
        let warpedW = warpedRatioW * REF_W;

        // --- 3. Interpolate ---
        const t = warpedRatioL; 
        const curr_vW_x = vW_start_x + (vW_end_x - vW_start_x) * t;
        const curr_vW_y = vW_start_y + (vW_end_y - vW_start_y) * t;

        const depthScale = 1 + (warpedW * P_DEPTH);

        return { 
            // ใช้ warpedL และ warpedW ในการคำนวณพิกัด
            x: orgX + (warpedL * vL_x * depthScale) + (warpedW * curr_vW_x), 
            y: orgY + (warpedL * vL_y * depthScale) + (warpedW * curr_vW_y) 
        };
    };

    const { isLDesk, side, AL, AW, l_radii } = options;
    const rBack = radii[0], rRight = radii[1], rFront = radii[2], rLeft = radii[3]; 

    let pen2D = { x: 0, y: 0 };
    const movePenTo = (x, y) => { pen2D = { x, y }; const p3d = project(x, y); path.moveTo(p3d.x, p3d.y); };
    const lineTo = (x, y) => { pen2D = { x, y }; const p3d = project(x, y); path.lineTo(p3d.x, p3d.y); };

    const arcTo3D = (x1, y1, x2, y2, radius) => {
        const p1 = {x: x1, y: y1}; const p2 = {x: x2, y: y2};
        if (radius <= 0) { lineTo(x1, y1); return; }
        const result = Math3DHelper.calculateArcTo3D(pen2D, p1, p2, radius, project);
        result.points.forEach(pt => {
            if (pt.type === 'line') path.lineTo(pt.p3d.x, pt.p3d.y);
            else if (pt.type === 'curve') path.quadraticCurveTo(pt.cp.x, pt.cp.y, pt.end.x, pt.end.y);
        });
        pen2D = result.newCurrent2D;
    };

    // --- CASE 1: RECTANGULAR ---
    if (!isLDesk) {
        movePenTo(0, rBack);
        arcTo3D(0, 0, L, 0, rBack); arcTo3D(L, 0, L, W, rRight);
        arcTo3D(L, W, 0, W, rFront); arcTo3D(0, W, 0, 0, rLeft);
        path.closePath();
        const legR_Anchor = project(L, W / 2); const legL_Anchor = project(0, W / 2); const legC_Anchor = project(L/2, W/2); 
        return { path, legR_Anchor, legL_Anchor, legC_Anchor };
    } 
    // --- CASE 2: L-SHAPE ---
    else {
        const { r_tl, r_tr, r_br, r_step, r_arm_bl, r_arm_br, r_in } = l_radii;
        if (side === 'right') {
            movePenTo(0, r_tl); arcTo3D(0, 0, L, 0, r_tl); arcTo3D(L, 0, L, AW, r_tr);
            arcTo3D(L, AW, L - AL, AW, r_arm_br); arcTo3D(L - AL, AW, L - AL, W, r_arm_bl);
            arcTo3D(L - AL, W, 0, W, r_in); arcTo3D(0, W, 0, 0, r_step);
            path.closePath();
        } else {
            movePenTo(0, r_tl); arcTo3D(0, 0, L, 0, r_tl); arcTo3D(L, 0, L, W, r_tr);
            arcTo3D(L, W, AL, W, r_br); arcTo3D(AL, W, AL, AW, r_in);
            arcTo3D(AL, AW, 0, AW, r_arm_br); arcTo3D(0, AW, 0, 0, r_arm_bl);
            path.closePath();
        }
        let legL_Anchor, legR_Anchor, legC_Anchor;
        if (side === 'right') {
            legL_Anchor = project(0, W/2); legR_Anchor = project(L, AW/2); legC_Anchor = project(L - AL + (AL/2), W/2); 
        } else {
            legL_Anchor = project(0, AW/2); legR_Anchor = project(L, W/2); legC_Anchor = project(AL/2, W/2);           
        }
        return { path, legR_Anchor, legL_Anchor, legC_Anchor };
    }
};

// ============================================================================
// 3. Main Draw Function (Updated for L-Desk Radii)
// ============================================================================
window.drawDesk3D = function() {
    const byId = function(id) { return document.getElementById(id); };
    const canvas = document.getElementById('dpb-canvas') || document.querySelector('canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    // --- Input & Config ---
    const modelType = (byId('dpb-type')?.value || 'custom').toLowerCase();
    const isLDesk = (modelType === 'l2' || modelType === 'l3');
    
    const L = +(byId('dpb-ml')?.value || 190);
    const W = +(byId('dpb-mw')?.value || 60);
    
    // L-Desk Params
    const AL = +(byId('dpb-al')?.value || 60); 
    const AW = +(byId('dpb-aw')?.value || 120); 
    const side = (byId('dpb-aside')?.value || 'right').toLowerCase();

    // Helper ดึงค่า R (เหมือนใน 2D)
    const getNum = (id, fb=0) => {
        const el = byId(id); const v = el ? Number(el.value) : fb;
        return Number.isFinite(v) ? v : fb;
    };

    // Radii for Rect Desk (mm -> cm)
    const rTL = getNum('r_rect_tl', 0) / 10;
    const rTR = getNum('r_rect_tr', 0) / 10;
    const rBR = getNum('r_rect_br', 0) / 10;
    const rBL = getNum('r_rect_bl', 0) / 10;

    // Radii for L-Desk (mm -> cm)
    // เก็บใส่ Object เพื่อส่งไปให้ createDynamicDeskPath
    const l_radii = {
        r_tl: getNum('ld_r_tl', 0) / 10,
        r_tr: getNum('ld_r_tr', 0) / 10,
        r_step: getNum('ld_r_step', 0) / 10,  // มุมล่างของแผ่นหลัก
        r_br: getNum('ld_r_br', 0) / 10,      // มุมขวาล่างแผ่นหลัก (สำหรับ L-Left)
        r_arm_bl: getNum('ld_r_armbl', 0) / 10,
        r_arm_br: getNum('ld_r_armbr', 0) / 10,
        r_in: getNum('dpb-rInner', 0) / 10    // มุมฉากด้านใน
    };

// --- Prepare Leg Assets (FIXED: Auto Load & Cache) ---
    const legData = window.getParamsForLegs(); 
    const legAssets = legData.assets;

    // 1. สร้างตัวแปรเก็บ Cache (ถ้ายังไม่มี) เพื่อไม่ให้โหลดซ้ำ
    if (!window.DPB_LEG_CACHE) window.DPB_LEG_CACHE = {};

    // 2. ฟังก์ชันช่วยโหลดรูป: ถ้าไม่มีใน Cache ให้โหลดใหม่ + สั่งวาดซ้ำเมื่อเสร็จ
    const loadLegImg = (url) => {
        if (!url) return new Image(); // กัน Error กรณีไม่มี URL
        if (!window.DPB_LEG_CACHE[url]) {
            const img = new Image();
            img.crossOrigin = "Anonymous";
            img.src = url;
            img.onload = () => { 
                // *** จุดสำคัญ: เมื่อโหลดรูปเสร็จ ให้เรียกฟังก์ชันวาดใหม่อีกรอบ ***
                if (window.drawDesk3D) requestAnimationFrame(window.drawDesk3D); 
            };
            window.DPB_LEG_CACHE[url] = img;
        }
        return window.DPB_LEG_CACHE[url];
    };
    
    // 3. เรียกใช้ผ่านฟังก์ชันโหลด
    const imgLegLeft   = loadLegImg(legAssets.left || legAssets.leg);
    const imgLegRight  = loadLegImg(legAssets.right || legAssets.leg);
    const imgLegCenter = loadLegImg(legAssets.center || legAssets.left);

    // Helper ดูดสี
    const getAverageColor = (imgEl) => {
        try {
            const c = document.createElement('canvas');
            c.width = 1; c.height = 1;
            const cx = c.getContext('2d');
            cx.drawImage(imgEl, 0, 0, 1, 1);
            const p = cx.getImageData(0, 0, 1, 1).data;
            return `rgb(${p[0]}, ${p[1]}, ${p[2]})`;
        } catch(e) { return '#5a4a3e'; }
    };

    // สร้าง Path
    const data = window.createDynamicDeskPath(L, W, [rTL, rTR, rBR, rBL], { 
        isLDesk, 
        side, 
        AL, 
        AW, 
        l_radii: l_radii // ส่งค่า R ของ L-Desk ไปด้วย
    });
    const shapePath = data.path;

    // --- Drawing Context Setup ---
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const bg = (window.state && window.state.theme && window.state.theme.bg) || '#ffffff';
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // --- Auto Zoom ---
    const maxDimL = isLDesk ? L : L;
    const maxDimW = isLDesk ? Math.max(W, AW) : W;
    const currMaxX = 616.9 + (maxDimL * 4.585);
    const currMinX = 616.9 + (maxDimW * -7.191);
    const tZoom = maxDimL / 200.0;
    const vw_end_y_est = 1.028 + (2.865 - 1.028) * tZoom;
    const currMaxY = 153.9 + (maxDimL * 2.469) + (maxDimW * vw_end_y_est);
    const LEG_LOGICAL_HEIGHT = 450; 
    const objectWidthLogic = currMaxX - currMinX;
    const objectHeightLogic = (currMaxY - 153.9) + LEG_LOGICAL_HEIGHT;
    const PADDING_X = 60; 
    const PADDING_Y = 100;
    const availableW = canvas.width - PADDING_X;
    const availableH = canvas.height - PADDING_Y;
    const scaleW = availableW / objectWidthLogic;
    const scaleH = availableH / objectHeightLogic;
    let sc3d = Math.min(scaleW, scaleH);

    const objCenterX = (currMinX + currMaxX) / 2;
    const objCenterY = (153.9 + (currMaxY + LEG_LOGICAL_HEIGHT)) / 2;
    const VISUAL_OFFSET_Y = -20; 
    const drawOffsetX = (canvas.width / 2) - (objCenterX * sc3d);
    const drawOffsetY = (canvas.height / 2) - (objCenterY * sc3d) + VISUAL_OFFSET_Y;

    // --- Shadow ---
    ctx.save();
    const SH_OFFSET_X = 150; const SH_OFFSET_Y = 380;    
    const SH_SCALE_X  = 1.02; const SH_SCALE_Y  = 1.05;    
    const SH_SKEW_X   = -0.3; const SH_SKEW_Y   = 0.11;    
    const SH_ROTATE   = -05; const SH_BLUR     = 25;       
    const SH_OPACITY  = 0.15;   
    const shadowX = drawOffsetX + (SH_OFFSET_X * sc3d);
    const shadowY = drawOffsetY + (SH_OFFSET_Y * sc3d);
    ctx.translate(shadowX, shadowY);
    ctx.rotate(SH_ROTATE * Math.PI / 180);
    ctx.transform(sc3d * SH_SCALE_X, SH_SKEW_Y, SH_SKEW_X, sc3d * SH_SCALE_Y, 0, 0);
    ctx.filter = `blur(${SH_BLUR}px)`;
    const shadowGrad = ctx.createLinearGradient(0, -100, 0, 200);
    shadowGrad.addColorStop(0.0, `rgba(0,0,0, ${SH_OPACITY * 0.1})`); 
    shadowGrad.addColorStop(0.5, `rgba(0,0,0, ${SH_OPACITY})`);        
    shadowGrad.addColorStop(1.0, `rgba(0,0,0, ${SH_OPACITY * 0.3})`); 
    ctx.fillStyle = shadowGrad;
    ctx.fill(shapePath);
    ctx.restore();

    // --- Legs (UPDATED: Configurable XY & Size) ---
    const UNIT_CONVERSION = 4.5855; 

    // ========================================================================
    // [CONFIG] LEG ADJUSTMENTS
    // ปรับแต่งขนาดและตำแหน่งขาแต่ละรุ่นได้ที่นี่
    // size: ขนาดความกว้างขา (cm)
    // moveX: เลื่อนซ้ายขวา (ค่าบวก = ขวา, ค่าลบ = ซ้าย)
    // moveUp: เลื่อนขึ้น (ค่าบวก = ขึ้น, ค่าลบ = ลง)
    // ========================================================================
    const LEG_CONFIG = {
        // รุ่น Custom (ขาคู่ทั่วไป)
        'custom': {
            left:   { size: 75, moveX: 10, moveUp: 60 },
            right:  { size: 90, moveX: 10, moveUp: 70 }

        },
        // รุ่น Single (ขาเดียว)
        'single': {
            center: { size: 80, moveX: 0, moveUp: 50 } 
        },
        // รุ่น L2 (2 ขา)
        'l2': {
            // กรณีหันขวา (Side Right)
            'right': {
                left:  { size: 75.0, moveX: 10, moveUp: 60 }, // ขาซ้าย
                right: { size: 116.0, moveX: 63.4, moveUp: 114.2 }  // ขาขวา (L)
            },
            // กรณีหันซ้าย (Side Left)
            'left': {
                left:  { size: 100.0, moveX: 63.4, moveUp: 90 }, // ขาซ้าย (L)
                right: { size: 90.0, moveX: 10, moveUp: 70 }  // ขาขวา
            }
        },
        // รุ่น L3 (3 ขา)
        'l3': {
            // กรณีหันขวา (Side Right)
            'right': {
                left:   { size: 64.7, moveX: 10, moveUp: 30 }, // ขาซ้าย
                center: { size: 64.7, moveX: 15, moveUp: 50 }, // ขาขวาบน (Center)
                right:  { size: 90.0, moveX: 20, moveUp: 70 }  // ขาขวาล่าง
            },
            // กรณีหันซ้าย (Side Left)
            'left': {
                left:   { size: 64.7, moveX: 10, moveUp: 30 }, // ขาซ้ายล่าง
                center: { size: 64.7, moveX: 15, moveUp: 50 }, // ขาซ้ายบน (Center)
                right:  { size: 90.0, moveX: 20, moveUp: 70 }  // ขาขวา
            }
        }
    };
    // กรณี Default หรือหาไม่เจอ
    const DEFAULT_LEG_CFG = { size: 80, moveX: 0, moveUp: 0 };


    // ฟังก์ชันวาดขาที่รองรับ Config
    const drawLeg = (img, pos, cfg) => {
        if (!img.complete || !pos || img.naturalWidth === 0) return;
        const config = cfg || DEFAULT_LEG_CFG;
        const LEG_SCALE_BOOST = 1.5; 
        
        const legW_Px = config.size * UNIT_CONVERSION * sc3d * LEG_SCALE_BOOST;
        const ratio = img.height / img.width;
        const legH_Px = legW_Px * ratio;

        // คำนวณตำแหน่ง (Apply moveX, moveUp)
        const posX = drawOffsetX + (pos.x * sc3d) - (legW_Px/2) + config.moveX;
        const posY = drawOffsetY + (pos.y * sc3d) - config.moveUp;

        ctx.drawImage(img, posX, posY, legW_Px, legH_Px);
    };

    // --- EXECUTE DRAWING ---
    // เลือกว่าจะใช้ Config ชุดไหน
    let currentCfg = LEG_CONFIG[modelType] || LEG_CONFIG['custom'];
    
    // ถ้าเป็น L2/L3 ต้องเจาะจง Side เข้าไปอีกชั้น
    if (modelType === 'l2' || modelType === 'l3') {
        currentCfg = currentCfg[side] || currentCfg['right'];
    }

    if (modelType === 'single') {
        drawLeg(imgLegLeft, data.legC_Anchor, currentCfg.center); 
    } 
    else if (modelType === 'l3') {
        // L3 วาด 3 ขา: Left, Center, Right
        // (ซึ่งเรา map รูปภาพใน assets ไว้ตรงกับตำแหน่งแล้ว)
        drawLeg(imgLegLeft,   data.legL_Anchor, currentCfg.left);
        drawLeg(imgLegCenter, data.legC_Anchor, currentCfg.center);
        drawLeg(imgLegRight,  data.legR_Anchor, currentCfg.right);
    }
    else if (modelType === 'l2') {
        // L2 วาด 2 ขา: Left, Right
        drawLeg(imgLegLeft,   data.legL_Anchor, currentCfg.left);
        drawLeg(imgLegRight,  data.legR_Anchor, currentCfg.right);
    }
    else {
        // Custom อื่นๆ
        drawLeg(imgLegLeft,   data.legL_Anchor, currentCfg.left);
        drawLeg(imgLegRight,  data.legR_Anchor, currentCfg.right);
    }

    // --- Texture ---
    let finalFillStyle = '#1a1a1a'; 
    let sideBaseColor = '#3d2e24';  
    const topColorVal = byId('dpb-top-color') ? byId('dpb-top-color').value : null;

    try {
        if (topColorVal) {
            let img = null;
            if (window.state && window.state.colorImg3DCache && window.state.colorImg3DCache[topColorVal]) {
                img = window.state.colorImg3DCache[topColorVal];
            }
            if (!img || !img.complete || img.naturalWidth === 0) {
                 if (window.DPB_TEXTURES && window.DPB_TEXTURES[topColorVal]) {
                     img = window.DPB_TEXTURES[topColorVal];
                 } else if (window.state && window.state.colorImgCache && window.state.colorImgCache[topColorVal]) {
                     img = window.state.colorImgCache[topColorVal];
                 }
            }
            if (img && img.complete && img.naturalWidth > 0) {
                 const p = ctx.createPattern(img, 'repeat');
                 const m = new DOMMatrix();
                 m.translateSelf(drawOffsetX, drawOffsetY);
                 m.scaleSelf(sc3d * 0.4, sc3d * 0.4); 
                 p.setTransform(m);
                 finalFillStyle = p;
                 sideBaseColor = getAverageColor(img);
            }
        } 
    } catch (e) { console.warn(e); }

    const thicknessPx = 4.0 * UNIT_CONVERSION * sc3d; 
    const layers = 55;
    const EXCLUDED_KEYS = ['MK630N', 'MK203', 'MK3200', 'MK260_AT1'];
    let isPlywoodMode = true; 
    if (EXCLUDED_KEYS.includes(topColorVal)) isPlywoodMode = false;
    else if (window.state?.meta?.colors) {
        const cData = window.state.meta.colors.find(c => c.key === topColorVal);
        if (cData && cData.group === 'solidwood') isPlywoodMode = false;
    }

    const gX1 = drawOffsetX + (currMinX * sc3d), gY1 = drawOffsetY + (153.9 * sc3d);
    const gX2 = drawOffsetX + (currMaxX * sc3d), gY2 = drawOffsetY + (currMaxY * sc3d);
    const layerShadowGradient = ctx.createLinearGradient(gX1, gY1, gX2, gY2);
    layerShadowGradient.addColorStop(0.0, "rgba(0, 0, 0, 0.00)"); 
    layerShadowGradient.addColorStop(0.6, "rgba(0, 0, 0, 0.00)"); 
    layerShadowGradient.addColorStop(1.0, "rgba(0, 0, 0, 0.15)");      

    const BAND_SIZE = 5; 
    ctx.save();
    for (let i = 0; i <= layers; i++) {
        let yShift = (thicknessPx * (layers - i)) / layers;
        ctx.setTransform(sc3d, 0, 0, sc3d, drawOffsetX, drawOffsetY + yShift);
        if (isPlywoodMode) {
            ctx.fillStyle = sideBaseColor; ctx.fill(shapePath);
            const isDarkBand = Math.floor(i / BAND_SIZE) % 2 === 0;
            ctx.fillStyle = isDarkBand ? 'rgba(0, 0, 0, 0.2)' : 'rgba(255, 255, 255, 0.05)';
            ctx.fill(shapePath);
        } else {
            ctx.fillStyle = finalFillStyle; ctx.fill(shapePath);
        }
        ctx.save();
        ctx.globalCompositeOperation = 'soft-light'; 
        ctx.fillStyle = layerShadowGradient;
        ctx.fill(shapePath);
        ctx.restore(); 
    }
    ctx.restore();

    // --- Top Face ---
    ctx.save();
    ctx.setTransform(sc3d, 0, 0, sc3d, drawOffsetX, drawOffsetY);
    ctx.clip(shapePath); 

    let fullImg3D = null;
    if (topColorVal && window.state?.colorImg3DCache?.[topColorVal]) {
        fullImg3D = window.state.colorImg3DCache[topColorVal];
    }
    if (fullImg3D && fullImg3D.complete && fullImg3D.naturalWidth > 0) {
        const boxX = currMinX, boxY = 153.9;
        const boxW = currMaxX - currMinX, boxH = currMaxY - 153.9;
        const cx = boxX + (boxW / 2), cy = boxY + (boxH / 2); 
        ctx.save(); ctx.translate(cx, cy);
        ctx.drawImage(fullImg3D, -boxW/2, -boxH/2, boxW, boxH);
        ctx.restore(); 
        ctx.fillStyle = 'rgba(0,0,0,0.05)'; ctx.fill(shapePath);
    } else {
        ctx.fillStyle = finalFillStyle; ctx.fill(shapePath);
    }
    ctx.strokeStyle = 'rgba(255,255,255,0.1)'; ctx.lineWidth = 2.5; ctx.stroke(shapePath);
    ctx.restore(); 

    ctx.save();
    ctx.setTransform(sc3d, 0, 0, sc3d, drawOffsetX, drawOffsetY);
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1.0;

    if (typeof window.drawOptionsIn3D === 'function') {
        const orgX = 616.9; 
        const orgY = 153.9;
        const vL_x = 4.5855, vL_y = 2.469; 
        const vW_start_x = -7.000, vW_start_y = 1.000;
        const vW_end_x   = -8.453, vW_end_y   = 2.865;
        
        const REF_L = 200.0;
        const REF_W = 100.0; // เพิ่มค่าอ้างอิงความลึก

        const P_DEPTH = 0.0005; 
        const P_STRENGTH_X = 1.20; 
        const P_STRENGTH_Y = 1.10; // <-- เพิ่มค่านี้ให้เท่ากับ createDynamicDeskPath

        const projectFn = (l, w) => {
            // 1. Warp Length (X)
            let ratioL = l / REF_L;
            let warpedRatioL = Math.pow(Math.abs(ratioL), P_STRENGTH_X);
            if (ratioL < 0) warpedRatioL = -warpedRatioL;
            let warpedL = warpedRatioL * REF_L;

            // 2. Warp Width/Depth (Y) <-- เพิ่ม Logic นี้
            let ratioW = w / REF_W;
            let warpedRatioW = Math.pow(Math.abs(ratioW), P_STRENGTH_Y);
            if (ratioW < 0) warpedRatioW = -warpedRatioW;
            let warpedW = warpedRatioW * REF_W;

            // 3. Interpolate
            const t = warpedRatioL; 
            const curr_vW_x = vW_start_x + (vW_end_x - vW_start_x) * t;
            const curr_vW_y = vW_start_y + (vW_end_y - vW_start_y) * t;

            const depthScale = 1 + (warpedW * P_DEPTH);

            return { 
                // ใช้ warpedL และ warpedW
                x: orgX + (warpedL * vL_x * depthScale) + (warpedW * curr_vW_x), 
                y: orgY + (warpedL * vL_y * depthScale) + (warpedW * curr_vW_y) 
            };
        };

        window.drawOptionsIn3D(ctx, L, W, projectFn);
    }
    ctx.restore();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
};



// ============================================================================
// [INIT] Re-run draw when leg assets are loaded
// ============================================================================
(function preloadLegAssets() {
    const allUrls = [];
    Object.values(LEG_3D_ASSETS).forEach(modelObj => {
        Object.values(modelObj).forEach(colorObj => {
            Object.values(colorObj).forEach(url => allUrls.push(url));
        });
    });
    
    let loaded = 0;
    allUrls.forEach(url => {
        const img = new Image();
        img.src = url;
        img.onload = () => {
            loaded++;
            if (loaded === allUrls.length && typeof window.draw === 'function') {
                window.draw(); // Refresh canvas when assets ready
            }
        };
    });
})();

window._dpbDimFocus = null;
window._dpbDimPulse = 1;
let _pulseRaf = null;
window._dpbOptFocus = null;  /* ← เพิ่มบรรทัดนี้ */


function startDimPulse(dimKey) {
  window._dpbDimFocus = dimKey;
  if (_pulseRaf) return;
  let t = 0;
  function loop() {
    t += 0.05;
    window._dpbDimPulse = 0.35 + 0.65 * (0.5 + 0.5 * Math.sin(t * Math.PI));
    scheduleRedraw();
    _pulseRaf = requestAnimationFrame(loop);
  }
  _pulseRaf = requestAnimationFrame(loop);
}

function stopDimPulse() {
  if (_pulseRaf) { cancelAnimationFrame(_pulseRaf); _pulseRaf = null; }
  window._dpbDimPulse  = 1;
  window._dpbDimFocus  = null;
  scheduleRedraw();
}

const dimInputMap = {
  'dpb-ml': 'length',   /* ความยาว → dimH บน */
  'dpb-mw': 'width',    /* ความกว้าง → dimV ขวา */
  'dpb-al': 'al',       /* ความยาวแขน L */
  'dpb-aw': 'aw',       /* ความกว้างแขน L */
};

Object.entries(dimInputMap).forEach(([id, key]) => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('focus', () => startDimPulse(key));
  el.addEventListener('blur',  () => stopDimPulse());
});

function draw() {
    if (!state?.validation?.ok) return;
    const requiredHeight = measureTotalHeight();

    if (canvas.height !== requiredHeight) {
        canvas.height = requiredHeight;
    }

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
    ctx.shadowBlur = 0;
    ctx.shadowColor = 'transparent';
    ctx.lineWidth = 1;

    // --- [จุดสำคัญ 1] เช็คโหมด 3D ---
    // ถ้าเป็น 3D ให้วาดแล้วจบฟังก์ชันเลย (Watermark ข้างล่างจะไม่ถูกเรียก)
    if (window.dpbViewMode === '3d') {
        window.drawDesk3D();
        return; 
    }

    // --- โหมด 2D ---
    
    // 1. เคลียร์จอ
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    setBoxes(null);

    // 2. ลงสีพื้นหลัง
    const bg = state?.theme?.bg || document.getElementById('dpb-bg')?.dataset.selected || '#ffffff';
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // 3. เตรียมตัวแปร
    const byId = function (id) { return document.getElementById(id); };
    const t = byId('dpb-type').value;
    const sc = deskScale();
    const wDesk = (typeof FIXED_DRAW_LEN !== 'undefined') ? FIXED_DRAW_LEN : 600;
    const xDesk = (canvas.width - wDesk) / 2;
    const yDesk = 150;
    let hDesk = 0;

    // 4. วาดโต๊ะ 2D (และคำนวณ hDesk ที่ถูกต้อง)
    if (t === 'l2' || t === 'l3') {
        hDesk = ldeskHeight(); // ความสูงสำหรับโต๊ะ L
        if (typeof drawLDeskAt === 'function') drawLDeskAt(xDesk, yDesk, sc, bg);
    } else {
        hDesk = rectDeskHeight(); // ความสูงสำหรับโต๊ะตรง
        if (typeof drawRectAt === 'function') drawRectAt(xDesk, yDesk, sc, bg);
    }

    // 5. [จุดสำคัญ 2] Watermark 2D Setup
    // แก้ไข: ใช้ตัวแปร hDesk ที่คำนวณมาแล้วจากข้อ 4 เพื่อความชัวร์
    try {
        const boxes = (typeof getBoxes === 'function') ? getBoxes() : (state && state.boxes);
        const r1 = boxes?.rect1 || boxes?.main;

        if (r1 && isFinite(r1.x)) {
            // กรณีมีข้อมูล Box ชัดเจน
            DPB_setWatermarkAnchor({ x: r1.x, y: r1.y, w: r1.w, h: r1.h }, sc);
        } else {
            // กรณี Fallback: ใช้ hDesk ที่เราคำนวณไว้แล้ว (แก้ปัญหา Watermark หายในโต๊ะ L)
            DPB_setWatermarkAnchor({ x: xDesk, y: yDesk, w: wDesk, h: hDesk }, sc);
        }
    } catch (_) { }

    // 6. วาด Options Grid (เฉพาะ 2D)
    const extraCm = (typeof getDeskBottomPaddingCm === 'function') ? getDeskBottomPaddingCm() : 0;
    const extraPx = extraCm * sc;
    const DESK_BOTTOM_SPACE = 80;
    const GAP_BETWEEN_OPTS = 0;
    const totalInnerW = canvas.width - (typeof PAD !== 'undefined' ? PAD.left : 20) - (typeof PAD !== 'undefined' ? PAD.right : 20);
    const optionsX = (typeof PAD !== 'undefined' ? PAD.left : 20);
    const optionsY = yDesk + hDesk + extraPx + DESK_BOTTOM_SPACE + GAP_BETWEEN_OPTS;

    if (typeof drawOptionsGridInBox === 'function') {
        drawOptionsGridInBox(optionsX, optionsY, totalInnerW);
    }

    if (typeof canShow3DButton !== 'undefined' && canShow3DButton === true && window.dpbViewMode !== '3d') {

        const sc_debug = (typeof deskScale === 'function') ? deskScale() : 1;
        const wDesk_debug = (typeof FIXED_DRAW_LEN !== 'undefined') ? FIXED_DRAW_LEN : 600;
        const xDesk_debug = (canvas.width - wDesk_debug)/2;
        const yDesk_debug = 150; 

        // 2. ดึงค่า Config โต๊ะ

        const byId = (id) => document.getElementById(id);
        const modelType = (byId('dpb-type')?.value || 'custom').toLowerCase();
        const isLDesk = (modelType === 'l2' || modelType === 'l3');
        const side = (byId('dpb-aside')?.value || 'right').toLowerCase();
        const ML = +(byId('dpb-ml')?.value || 180);
        const MW = +(byId('dpb-mw')?.value || 70);
        const AL = +(byId('dpb-al')?.value || 70);
        const AW = +(byId('dpb-aw')?.value || 110);



        // 3. เรียกวาด Main Table

       // if (typeof window.draw2DDebugRuler === 'function') {

        //    window.draw2DDebugRuler(ctx, xDesk_debug, yDesk_debug, ML, MW, sc_debug, 'MAIN');

        //}



        // 4. เรียกวาด Arm (ถ้ามี)

       // if (isLDesk && typeof window.draw2DDebugRuler === 'function') {

        //    let armX = xDesk_debug;

            // คำนวณตำแหน่ง Arm สำหรับ L-Right

        //    if (side === 'right') {

         //       armX = xDesk_debug + ((ML - AL) * sc_debug);

         //   }

         //   window.draw2DDebugRuler(ctx, armX, yDesk_debug, AL, AW, sc_debug, 'ARM');

        //}

    }


    try { DPB_applyWatermarkAutoColor(); } catch (_) { }
    try { DPB_drawBrandWatermark_OnTop(); } catch (_) { }
    try { dpb_drawStatusIndicatorDots(); } catch (_) { }
}


function drawOptions(box, bgColor, sc) {
    const cIn = getInColor();
    const toPx = v => v * sc;
    const TH_PX = 5 * sc;
    const RED = '#ff2d2d';
    const typeNow = (byId('dpb-type')?.value || '').toLowerCase();
    const aside = (byId('dpb-aside')?.value || 'right').toLowerCase();
    const IS_LDESK = (typeNow === 'l2' || typeNow === 'l3');
    const DBG = !!window.DPB_DEBUG;

    window.state = window.state || {};
    window.state.hitRegions = [];

    const buckets = { main: [], arm: [] };
    let rectMain = box;
    let rectArm = null;

    if (IS_LDESK) {
        const lStruct = ensureL3Rects({ box }, sc);
        rectMain = lStruct.rect1 || state.boxes?.main || box;
        rectArm = lStruct.rect2 || state.boxes?.arm || null;
        if (!rectArm) {
            const def = getTypeDefaults(typeNow);
            const AL = Number(byId('dpb-al')?.value || def.al || 60) * sc;
            const AW = Number(byId('dpb-aw')?.value || def.aw || 120) * sc;
            const L = Number(byId('dpb-ml')?.value || def.ml || 180) * sc;
            rectArm = (aside === 'right') ? { x: rectMain.x + L - AL, y: rectMain.y, w: AL, h: AW } : { x: rectMain.x, y: rectMain.y, w: AL, h: AW };
        }
    } else {
        rectMain = state.boxes?.main || box;
    }

    let shadowCtx = null;
    try {
        const shadowCv = document.createElement('canvas');
        shadowCv.width = ctx.canvas.width;
        shadowCv.height = ctx.canvas.height;
        shadowCtx = shadowCv.getContext('2d', { willReadFrequently: true });
        if (typeof window.drawLegsForScan === 'function') {
            window.drawLegsForScan(shadowCtx, sc, rectMain, rectArm);
        }
    } catch (e) {
        console.warn('Shadow Canvas Error', e);
    }

    function pathHole(d) {
        ctx.beginPath();
        if (d.isCircle) {
            const r = d.rw / 2;
            ctx.arc(d.leftX + r, d.topY + r, r, 0, Math.PI * 2);
        } else {
            ctx.rect(d.leftX, d.topY, d.rw, d.rh);
        }
    }

    function drawArrowTip(x, y, angle, color) {
        const size = 6;
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(angle);
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(-size, -size / 1.8);
        ctx.lineTo(-size, size / 1.8);
        ctx.closePath();
        ctx.fillStyle = color;
        ctx.fill();
        ctx.restore();
    }

    function drawPlainLineH(x1, x2, y, c) {
        ctx.save();
        ctx.beginPath();
        ctx.strokeStyle = c;
        ctx.lineWidth = 1.4;
        ctx.moveTo(x1, y); ctx.lineTo(x2, y); ctx.stroke();
        ctx.restore();
    }

    function isOptActive(recKey, recIndex, field) {
        const f = window._dpbOptFocus;
        if (!f) return false;
        if (f.key !== recKey || f.index !== recIndex) return false;
        if (field && f.field !== field) return false;
        return true;
    }

    function applyOptFill(recKey, recIndex, field) {
        const active = isOptActive(recKey, recIndex, field);
        const pulse = active ? (window._dpbDimPulse ?? 1) : 1;
        ctx.fillStyle = active ? '#ff2020' : cIn;
        ctx.globalAlpha = active ? (0.5 + 0.5 * pulse) : 1;
        ctx.shadowColor = active ? '#ff0000' : 'transparent';
        ctx.shadowBlur = active ? (10 * (1 - pulse)) : 0;
    }

    function resetCtxFx() {
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
        ctx.shadowColor = 'transparent';
    }

    function drawDimLineH(x1, x2, y, c, recKey, recIndex) {
        const active = isOptActive(recKey, recIndex, 'offsetX');
        const pulse = active ? (window._dpbDimPulse ?? 1) : 1;
        ctx.save();
        ctx.strokeStyle = active ? '#ff2020' : c;
        ctx.lineWidth = active ? (1.4 + 2 * (1 - pulse)) : 1.4;
        ctx.globalAlpha = active ? (0.5 + 0.5 * pulse) : 1;
        ctx.shadowColor = active ? '#ff0000' : 'transparent';
        ctx.shadowBlur = active ? (16 * (1 - pulse)) : 0;
        ctx.beginPath(); ctx.moveTo(x1, y); ctx.lineTo(x2, y); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(x1, y - 4); ctx.lineTo(x1, y + 4); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(x2, y - 4); ctx.lineTo(x2, y + 4); ctx.stroke();
        ctx.restore();
    }

    function drawDimLineV(y1, y2, x, c, recKey, recIndex) {
        const active = isOptActive(recKey, recIndex, 'offsetY');
        const pulse = active ? (window._dpbDimPulse ?? 1) : 1;
        ctx.save();
        ctx.strokeStyle = active ? '#ff2020' : c;
        ctx.lineWidth = active ? (1.4 + 2 * (1 - pulse)) : 1.4;
        ctx.globalAlpha = active ? (0.5 + 0.5 * pulse) : 1;
        ctx.shadowColor = active ? '#ff0000' : 'transparent';
        ctx.shadowBlur = active ? (16 * (1 - pulse)) : 0;
        ctx.beginPath(); ctx.moveTo(x, y1); ctx.lineTo(x, y2); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(x - 4, y1); ctx.lineTo(x + 4, y1); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(x - 4, y2); ctx.lineTo(x + 4, y2); ctx.stroke();
        ctx.restore();
    }

    function drawArrowH(x1, x2, y, color, recKey, recIndex) {
        if (x2 < x1) { const t = x1; x1 = x2; x2 = t; }
        const active = isOptActive(recKey, recIndex, 'offsetX');
        const pulse = active ? (window._dpbDimPulse ?? 1) : 1;
        const drawC = active ? '#ff2020' : color;
        ctx.save();
        ctx.strokeStyle = drawC;
        ctx.lineWidth = active ? (1.4 + 2 * (1 - pulse)) : 1.4;
        ctx.globalAlpha = active ? (0.5 + 0.5 * pulse) : 1;
        ctx.shadowColor = active ? '#ff0000' : 'transparent';
        ctx.shadowBlur = active ? (16 * (1 - pulse)) : 0;
        ctx.beginPath(); ctx.moveTo(x1, y); ctx.lineTo(x2, y); ctx.stroke();
        drawArrowTip(x1, y, Math.PI, drawC);
        drawArrowTip(x2, y, 0, drawC);
        ctx.restore();
    }

    function drawArrowV(y1, y2, x, color, recKey, recIndex) {
        if (y2 < y1) { const t = y1; y1 = y2; y2 = t; }
        const active = isOptActive(recKey, recIndex, 'offsetY');
        const pulse = active ? (window._dpbDimPulse ?? 1) : 1;
        const drawC = active ? '#ff2020' : color;
        ctx.save();
        ctx.strokeStyle = drawC;
        ctx.lineWidth = active ? (1.4 + 2 * (1 - pulse)) : 1.4;
        ctx.globalAlpha = active ? (0.5 + 0.5 * pulse) : 1;
        ctx.shadowColor = active ? '#ff0000' : 'transparent';
        ctx.shadowBlur = active ? (16 * (1 - pulse)) : 0;
        ctx.beginPath(); ctx.moveTo(x, y1); ctx.lineTo(x, y2); ctx.stroke();
        drawArrowTip(x, y1, -Math.PI / 2, drawC);
        drawArrowTip(x, y2, Math.PI / 2, drawC);
        ctx.restore();
    }

    Object.keys(state.selectedOptions).forEach(key => {
        const sel = state.selectedOptions[key]; if (!sel || !sel.count) return;
        const arr = state.optConfig[key] || [];
        for (let i = 0; i < sel.count; i++) {
            const cfg = arr[i]; if (!cfg) continue;
            const op = (state.meta.options || []).find(o => o.key === key) || { type: 'hole_rect' };
            if (String(op.type || '').toLowerCase() === 'attach') continue;

            let finalImgUrl = op.imageUrl;
            if (op.variants && typeof cfg.variantIndex !== 'undefined') {
                const v = op.variants[cfg.variantIndex];
                if (v && v.imageUrl) {
                    finalImgUrl = v.imageUrl;
                }
            }
            if (finalImgUrl && typeof finalImgUrl === 'string' && finalImgUrl.includes(',')) {
                finalImgUrl = finalImgUrl.split(',')[0].trim();
            }

            const posMode = (cfg.pos || 'main').toLowerCase();
            const placeRaw = String(cfg.place || '').toLowerCase();
            const isCircle = (op.type === 'hole_circle');
            const isRotated = !!cfg.rotate && !isCircle;

            let boxUse = rectMain;
            if (IS_LDESK) {
                if (isRotated || (isCircle && !!cfg.rotate)) {
                    const isArmSide = (aside === 'right' && ['right', 'ขวา', 'ด้านขวา'].includes(placeRaw)) ||
                        (aside === 'left' && ['left', 'ซ้าย', 'ด้านซ้าย'].includes(placeRaw));
                    if (isArmSide) boxUse = rectArm || rectMain;
                }
                if ((posMode === 'left' || posMode === 'right') && posMode === aside) {
                    boxUse = rectArm || rectMain;
                }
            }

            const { x, y, w, h } = boxUse;
            let rw = isRotated ? toPx(cfg.h) : toPx(cfg.w);
            let rh = isRotated ? toPx(cfg.w) : toPx(cfg.h);
            if (isCircle) { rw = toPx(cfg.w); rh = toPx(cfg.w); }

            if (!(cfg.w > 0) || (!isCircle && !(cfg.h > 0))) continue;

            const fromRaw = String(cfg.from || '').toLowerCase();
            const isBottomFrom = ['bottom', 'ด้านล่าง', 'down', 'below'].includes(fromRaw);
            const isLRight_Corner = (IS_LDESK && aside === 'right' && isBottomFrom && ['right', 'ขวา'].includes(placeRaw) && posMode === 'main');
            const isLLeft_Corner = (IS_LDESK && aside === 'left' && isBottomFrom && ['left', 'ซ้าย'].includes(placeRaw) && posMode === 'main');
            const USE_ARM_BOTTOM = isLRight_Corner || isLLeft_Corner;

            let refBottomY;
            if (boxUse === rectArm) refBottomY = rectArm.y + rectArm.h;
            else if (USE_ARM_BOTTOM) refBottomY = rectArm ? (rectArm.y + rectArm.h) : (y + h);
            else refBottomY = rectMain.y + rectMain.h;

            let topY, leftX;
            if (['top', 'บน'].includes(fromRaw)) topY = y + toPx(cfg.offsetY || 0);
            else if (['center', 'ตรงกลาง'].includes(fromRaw)) topY = y + (h - rh) / 2;
            else topY = refBottomY - toPx(cfg.offsetY || 0) - rh;

            if (['left', 'ซ้าย', 'ด้านซ้าย'].includes(placeRaw)) leftX = x + toPx(cfg.offsetX || 0);
            else if (['right', 'ขวา', 'ด้านขวา'].includes(placeRaw)) leftX = x + w - toPx(cfg.offsetX || 0) - rw;
            else leftX = x + (w - rw) / 2;

            const bk = (IS_LDESK && boxUse === rectArm) ? 'arm' : 'main';
            const drawPayload = { leftX, topY, rw, rh, isCircle, isRotated, USE_ARM_BOTTOM, refBottomY, boxUsed: boxUse, cfgSnapshot: { ...cfg }, fromRaw, placeRaw, posMode, imgUrl: finalImgUrl };

            if (isCircle) buckets[bk].push({ key, index: i, cfg, op, box: boxUse, shape: 'circle', cx: leftX + rw / 2, cy: topY + rh / 2, r: rw / 2, draw: drawPayload, imgUrl: finalImgUrl });
            else buckets[bk].push({ key, index: i, cfg, op, box: boxUse, shape: 'rect', x: leftX, y: topY, w: rw, h: rh, draw: drawPayload, imgUrl: finalImgUrl });
        }
    });

    const violIdx = { main: new Set(), arm: new Set() };
    ['main', 'arm'].forEach(bk => {
        const arr = buckets[bk];
        const sortedArr = [...arr].sort((a, b) => a.draw.leftX - b.draw.leftX);

        function _minGapPx(a, b) {
            const A = a.draw, B = b.draw;
            const xA1 = A.leftX, xA2 = A.leftX + A.rw;
            const xB1 = B.leftX, xB2 = B.leftX + B.rw;
            const yA1 = A.topY, yA2 = A.topY + A.rh;
            const yB1 = B.topY, yB2 = B.topY + B.rh;
            const gapX = Math.max(xB1 - xA2, xA1 - xB2, 0);
            const gapY = Math.max(yB1 - yA2, yA1 - yB2, 0);
            if (gapX === 0 && gapY === 0) return 0;
            if (gapX === 0) return gapY;
            if (gapY === 0) return gapX;
            return Math.sqrt(gapX * gapX + gapY * gapY);
        }

        for (let i = 0; i < arr.length; i++) {
            for (let j = i + 1; j < arr.length; j++) {
                const gap = _minGapPx(arr[i], arr[j]);
                if (gap < TH_PX) {
                    violIdx[bk].add(i);
                    violIdx[bk].add(j);
                    [arr[i], arr[j]].forEach(rec => {
                        const card = document.querySelector(`.dpb-cart-item[data-key="${CSS.escape(rec.key)}"][data-index="${rec.index}"]`);
                        const inputEl = card ? (card.querySelector('input[name="offsetX"]') || card.querySelector('input[name="offsetY"]')) : null;
                        function getFullLabel(rec) {
                            const op = rec.op || {};
                            const cfg = rec.cfg || {};
                            const name = op.name || rec.key;
                            const variant = String(cfg.variant || '').trim();
                            const num = rec.index + 1;
                            return variant ? `${name} (${variant}) #${num}` : `${name} #${num}`;
                        }
                        const labelA = getFullLabel(arr[i]);
                        const labelB = getFullLabel(arr[j]);
                        const gapCm = (gap / sc).toFixed(1);
                        const msg = gap === 0
                            ? `${labelA} และ ${labelB} ทับซ้อนกัน - กรุณาขยับระยะห่าง`
                            : `${labelA} และ ${labelB} ใกล้กันเกินไป (${gapCm} cm) — แนะนำให้ห่างอย่างน้อย 5 cm`;
                        if (inputEl) setFieldError(inputEl, msg, false, msg);
                    });
                }
            }
        }

        arr.forEach((rec, i) => {
            if (!violIdx[bk].has(i)) {
                const card = document.querySelector(`.dpb-cart-item[data-key="${CSS.escape(rec.key)}"][data-index="${rec.index}"]`);
                const inputEl = card ? (card.querySelector('input[name="offsetX"]') || card.querySelector('input[name="offsetY"]')) : null;
                if (inputEl) setFieldError(inputEl, '', false);
            }
        });

        arr.forEach((rec, i) => {
            const d = rec.draw;
            ctx.save(); pathHole(d); ctx.globalCompositeOperation = 'destination-out'; ctx.fillStyle = '#000'; ctx.fill(); ctx.restore();
            if (typeof paintRedLegsInsideHole === 'function') paintRedLegsInsideHole(rec);
            ctx.save(); pathHole(d); ctx.globalCompositeOperation = 'source-over'; ctx.setLineDash([]);
            ctx.strokeStyle = violIdx[bk].has(i) ? RED : cIn;
            ctx.lineWidth = violIdx[bk].has(i) ? 2.5 : 2;
            ctx.stroke(); ctx.restore();
            window.state.hitRegions.push({ key: rec.key, index: rec.index, rect: { x: d.leftX, y: d.topY, w: d.rw, h: d.rh }, refBox: d.boxUsed, cfg: rec.cfg });

            if (shadowCtx) {
                const foundRedLeg = scanLegColors(shadowCtx, d.leftX, d.topY, d.rw, d.rh, d.isCircle);
                if (foundRedLeg) {
                    const inputId = `dpb-opt-val-${rec.key}-${rec.index}`;
                    let inputEl = document.getElementById(inputId);
                    if (!inputEl) {
                        const card = document.querySelector(`.dpb-cart-item[data-key="${CSS.escape(rec.key)}"][data-index="${rec.index}"]`);
                        if (card) inputEl = card.querySelector('input[name="offsetY"]');
                    }
                    if (inputEl) setFieldError(inputEl, 'กรุณาขยับ Option ให้ไม่อยู่บนขาโต๊ะ', false, 'กรุณาขยับ Option ให้ไม่อยู่บนขาโต๊ะ');
                }
            }
        });

        arr.forEach((rec) => {
            const d = rec.draw;
            const { leftX, topY, rw, rh, isCircle, isRotated, fromRaw, placeRaw, USE_ARM_BOTTOM, refBottomY, boxUsed } = d;
            const { cfg } = rec;

            const myRank = sortedArr.indexOf(rec);
            const totalItems = sortedArr.length;

            let prevItem = null, nextItem = null;
            for (let k = myRank - 1; k >= 0; k--) { if (sortedArr[k].draw.fromRaw === fromRaw) { prevItem = sortedArr[k]; break; } }
            for (let k = myRank + 1; k < totalItems; k++) { if (sortedArr[k].draw.fromRaw === fromRaw) { nextItem = sortedArr[k]; break; } }

            const rightOrLeft = (p) => ['right', 'ขวา', 'ด้านขวา', 'left', 'ซ้าย', 'ด้านซ้าย'].includes(p);

            let neighborAbove = arr.find(o => o !== rec && rightOrLeft(o.draw.placeRaw) && o.draw.placeRaw === placeRaw && o.draw.topY < topY);
            if (!neighborAbove && bk === 'arm' && buckets['main']) {
                neighborAbove = buckets['main'].find(o => rightOrLeft(o.draw.placeRaw) && o.draw.placeRaw === placeRaw && o.draw.topY < topY);
            }
            const neighborBelow = arr.find(o => o !== rec && rightOrLeft(o.draw.placeRaw) && o.draw.placeRaw === placeRaw && o.draw.topY > topY);
            const hasNeighborAbove = !!neighborAbove;
            const hasNeighborBelow = !!neighborBelow;
            const isSandwiched = hasNeighborAbove && hasNeighborBelow;

            const isRightSide = ['right', 'ขวา', 'ด้านขวา'].includes(placeRaw);
            const isLeftSide = ['left', 'ซ้าย', 'ด้านซ้าย'].includes(placeRaw);
            const isTopFrom = ['top', 'บน'].includes(fromRaw);
            const isCenterFrom = ['center', 'ตรงกลาง'].includes(fromRaw);

            let measureYDir = 'bottom';
            if (isTopFrom) {
                measureYDir = 'top';
            } else if (isCenterFrom) {
                if (isSandwiched) {
                    if (isRightSide) {
                        const leftX_Above = neighborAbove.draw.leftX;
                        const leftX_Below = neighborBelow.draw.leftX;
                        if (leftX_Below >= leftX_Above) measureYDir = 'bottom';
                        else measureYDir = 'top';
                    } else if (isLeftSide) {
                        const rightEdge_Above = neighborAbove.draw.leftX + neighborAbove.draw.rw;
                        const rightEdge_Below = neighborBelow.draw.leftX + neighborBelow.draw.rw;
                        if (rightEdge_Below <= rightEdge_Above) measureYDir = 'bottom';
                        else measureYDir = 'top';
                    } else {
                        measureYDir = 'bottom';
                    }
                } else if (hasNeighborBelow && !hasNeighborAbove) {
                    measureYDir = 'top';
                } else {
                    measureYDir = 'bottom';
                }
            } else {
                measureYDir = 'bottom';
            }

            let measureDir = 'auto';
            let targetLineY = topY + rh / 2;
            let showLeaderLine = false;
            let isDodging = false;

            const distToLeftEdge = leftX - boxUsed.x;
            const distToRightEdge = (boxUsed.x + boxUsed.w) - (leftX + rw);

            let maxBottomLeft = -Infinity;
            for (let k = 0; k < myRank; k++) {
                const nb = sortedArr[k];
                const nbBottom = Math.max(nb.draw.topY + nb.draw.rh, (nb.draw.targetLineY || 0));
                if (isTopFrom && ['top', 'บน'].includes(nb.draw.fromRaw)) maxBottomLeft = Math.max(maxBottomLeft, nbBottom);
                else if (!isTopFrom) maxBottomLeft = Math.max(maxBottomLeft, nbBottom);
            }

            let maxBottomRight = -Infinity;
            for (let k = myRank + 1; k < totalItems; k++) {
                const nb = sortedArr[k];
                const nbBottom = Math.max(nb.draw.topY + nb.draw.rh, (nb.draw.targetLineY || 0));
                if (isTopFrom && ['top', 'บน'].includes(nb.draw.fromRaw)) maxBottomRight = Math.max(maxBottomRight, nbBottom);
                else if (!isTopFrom) maxBottomRight = Math.max(maxBottomRight, nbBottom);
            }

            const baseLineY = topY + rh / 2;
            const costLeft = (maxBottomLeft === -Infinity) ? baseLineY : maxBottomLeft + TH_PX;
            const costRight = (maxBottomRight === -Infinity) ? baseLineY : maxBottomRight + TH_PX;

            const isUserRight = ['right', 'ขวา', 'ด้านขวา'].includes(placeRaw);
            const isUserLeft = ['left', 'ซ้าย', 'ด้านซ้าย'].includes(placeRaw);

            if (isUserRight) {
                measureDir = 'right';
            } else if (isUserLeft) {
                measureDir = 'left';
            } else if (['center', 'ตรงกลาง'].includes(placeRaw)) {
                if (myRank === 0) measureDir = 'left';
                else if (myRank === totalItems - 1) measureDir = 'right';
                else measureDir = (costRight < costLeft) ? 'right' : 'left';
            } else {
                measureDir = (distToRightEdge < distToLeftEdge) ? 'right' : 'left';
            }

            if (measureDir === 'right' && !isUserRight) {
                const rightCrowd = sortedArr.slice(myRank + 1).find(it => {
                    const targetBot = it.draw.topY + it.draw.rh;
                    const targetTop = it.draw.topY;
                    return ['right', 'ขวา', 'ด้านขวา'].includes(it.draw.placeRaw) &&
                        (baseLineY < targetBot + toPx(5) && baseLineY > targetTop - toPx(5));
                });
                if (rightCrowd) measureDir = 'left';
            }

            const allowDodgeLeft = ['center', 'ตรงกลาง', 'left', 'ซ้าย', 'ด้านซ้าย'].includes(placeRaw);
            const allowDodgeRight = ['center', 'ตรงกลาง', 'right', 'ขวา', 'ด้านขวา'].includes(placeRaw);

            if (measureDir === 'left') {
                if (myRank === 0) {
                    targetLineY = baseLineY;
                    showLeaderLine = false;
                    isDodging = false;
                } else if (costLeft > baseLineY && ['center', 'ตรงกลาง', 'left', 'ซ้าย'].includes(placeRaw)) {
                    targetLineY = costLeft + toPx(5);
                    showLeaderLine = true;
                    isDodging = true;
                }
            } else if (measureDir === 'right') {
                if (myRank === totalItems - 1) {
                    targetLineY = baseLineY;
                    showLeaderLine = false;
                    isDodging = false;
                } else if (costRight > baseLineY && ['center', 'ตรงกลาง', 'right', 'ขวา'].includes(placeRaw)) {
                    targetLineY = costRight + toPx(5);
                    showLeaderLine = true;
                    isDodging = true;
                }
            }

            if (IS_LDESK && boxUsed === rectArm) {
                if (aside === 'right') measureDir = 'right';
                else if (aside === 'left') measureDir = 'left';
            }

            ctx.save();
            ctx.globalCompositeOperation = 'source-over';
            ctx.setLineDash([]);

            let wyLineY;
            if (measureYDir === 'bottom') wyLineY = topY - 6;
            else wyLineY = topY + rh + 6;

            let sizeLineSide = (measureDir === 'left') ? 'right' : 'left';
            if (isRightSide) sizeLineSide = 'left';

            if (isCircle) {
                const wyTextY = (measureYDir === 'bottom') ? (wyLineY - 8) : (wyLineY + 16);
                drawDimLineH(leftX, leftX + rw, wyLineY, cIn);
                ctx.fillStyle = cIn;
                ctx.font = '400 13px Prompt,sans-serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
                ctx.fillText(`${cfg.w} cm`, leftX + rw / 2, wyTextY);

            } else if (isRotated) {
                let wxLineX, wxAlign;
                if (sizeLineSide === 'right') { wxLineX = leftX + rw + 6; wxAlign = 'left'; }
                else { wxLineX = leftX - 6; wxAlign = 'right'; }

                drawDimLineV(topY, topY + rh, wxLineX, cIn);
                ctx.save();
                ctx.translate(wxLineX, topY + rh / 2);
                ctx.rotate(wxAlign === 'right' ? -Math.PI / 2 : Math.PI / 2);
                ctx.fillStyle = cIn;
                ctx.font = '400 13px Prompt,sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`${cfg.w} cm`, 0, -10);
                ctx.restore();

                const finalHyTextY = (measureYDir === 'bottom') ? (wyLineY - 8) : (wyLineY + 16);
                drawDimLineH(leftX, leftX + rw, wyLineY, cIn);
                ctx.fillStyle = cIn;
                ctx.font = '400 13px Prompt,sans-serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
                ctx.fillText(`${cfg.h} cm`, leftX + rw / 2, finalHyTextY);

            } else {
                const wyTextY = (measureYDir === 'bottom') ? (wyLineY - 8) : (wyLineY + 16);
                drawDimLineH(leftX, leftX + rw, wyLineY, cIn);
                ctx.fillStyle = cIn;
                ctx.font = '400 13px Prompt,sans-serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
                ctx.fillText(`${cfg.w} cm`, leftX + rw / 2, wyTextY);

                let hxLineX, hxAlign;
                if (sizeLineSide === 'right') { hxLineX = leftX + rw + 6; hxAlign = 'left'; }
                else { hxLineX = leftX - 6; hxAlign = 'right'; }

                drawDimLineV(topY, topY + rh, hxLineX, cIn);
                ctx.save();
                ctx.translate(hxLineX, topY + rh / 2);
                ctx.rotate(hxAlign === 'right' ? -Math.PI / 2 : Math.PI / 2);
                ctx.fillStyle = cIn;
                ctx.font = '400 13px Prompt,sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`${cfg.h} cm`, 0, -10);
                ctx.restore();
            }

            function num(v) { const n = Number(v); return Number.isFinite(n) ? n : null; }
            let valY = num(cfg.offsetY);
            let valX = num(cfg.offsetX);

            if (isCenterFrom) {
                if (measureYDir === 'top') {
                    const centerCm = (topY - boxUsed.y) / sc;
                    valY = Math.round(centerCm * 10) / 10;
                } else {
                    const centerCm = ((boxUsed.h - rh) / 2) / sc;
                    valY = Math.round(centerCm * 10) / 10;
                }
            } else if (['center', 'ตรงกลาง'].includes(fromRaw)) {
                const centerCm = ((boxUsed.h - rh) / 2) / sc;
                valY = Math.round(centerCm * 10) / 10;
            }
            if (['center', 'ตรงกลาง'].includes(placeRaw)) {
                const centerCm = ((boxUsed.w - rw) / 2) / sc;
                valX = Math.round(centerCm * 10) / 10;
            }

            if (valY != null && !cfg.hideDim) {
                const arrowX = leftX + rw / 2;
                const spaceLeft = arrowX - boxUsed.x;
                const spaceRight = (boxUsed.x + boxUsed.w) - arrowX;
                const textOnRight = spaceLeft < spaceRight;

                const drawYText = (txt, yPos) => {
                    ctx.save();
                    applyOptFill(rec.key, rec.index, 'offsetY');
                    ctx.font = '400 13px Prompt,sans-serif';
                    ctx.textAlign = textOnRight ? 'left' : 'right';
                    ctx.textBaseline = 'middle';
                    const xOffset = textOnRight ? 12 : -12;
                    ctx.fillText(txt, arrowX + xOffset, yPos);
                    ctx.restore();
                };

                if (isCenterFrom && isSandwiched && isLeftSide) {
                    const isDown = (measureYDir === 'bottom');
                    const neighborRef = isDown ? neighborBelow : neighborAbove;
                    const obstacleRightX = neighborRef.draw.leftX + neighborRef.draw.rw;
                    const detourX = obstacleRightX + toPx(10);
                    const yOrigin = isDown ? (topY + rh) : topY;
                    const yDest = isDown ? refBottomY : boxUsed.y;
                    ctx.save(); ctx.setLineDash([4, 3]);
                    drawPlainLineH(leftX + rw / 2, detourX, yOrigin, cIn);
                    ctx.restore();
                    drawArrowV(yOrigin, yDest, detourX, cIn, rec.key, rec.index);
                    ctx.save();
                    const textY = yOrigin + (yDest - yOrigin) / 2;
                    ctx.translate(detourX, textY);
                    ctx.rotate(Math.PI / 2);
                    applyOptFill(rec.key, rec.index, 'offsetY');
                    ctx.font = '400 13px Prompt,sans-serif';
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(`${valY} cm`, 0, -14);
                    ctx.restore();

                } else if (isCenterFrom && isSandwiched && isRightSide) {
                    let obstacleLeftX, yOrigin, yDest;
                    if (measureYDir === 'bottom') {
                        obstacleLeftX = neighborBelow.draw.leftX;
                        yOrigin = topY + rh; yDest = refBottomY;
                    } else {
                        obstacleLeftX = neighborAbove.draw.leftX;
                        yOrigin = topY; yDest = boxUsed.y;
                    }
                    const detourX = obstacleLeftX - toPx(10);
                    ctx.save(); ctx.setLineDash([4, 3]);
                    drawPlainLineH(leftX + rw / 2, detourX, yOrigin, cIn);
                    ctx.restore();
                    if (measureYDir === 'bottom') drawArrowV(yOrigin, yDest, detourX, cIn, rec.key, rec.index);
                    else drawArrowV(yDest, yOrigin, detourX, cIn, rec.key, rec.index);
                    ctx.save();
                    const textY = measureYDir === 'bottom' ? (yOrigin + (yDest - yOrigin) / 2) : (yDest + (yOrigin - yDest) / 2);
                    ctx.translate(detourX, textY);
                    ctx.rotate(-Math.PI / 2);
                    applyOptFill(rec.key, rec.index, 'offsetY');
                    ctx.font = '400 13px Prompt,sans-serif';
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(`${valY} cm`, 0, -14);
                    ctx.restore();

                } else if (isRightSide && hasNeighborBelow && !isCenterFrom && measureYDir === 'bottom') {
                    const safeBuffer = toPx(12);
                    const detourX = leftX - safeBuffer;
                    let yOrigin = topY + rh;
                    let yDest = refBottomY;
                    let yDetourText = yOrigin + (yDest - yOrigin) / 2;
                    ctx.save(); ctx.setLineDash([4, 3]);
                    drawPlainLineH(leftX + rw / 2, detourX, yOrigin, cIn);
                    ctx.restore();
                    drawArrowV(yOrigin, yDest, detourX, cIn, rec.key, rec.index);
                    ctx.save();
                    ctx.translate(detourX, yDetourText);
                    ctx.rotate(isRightSide ? -Math.PI / 2 : Math.PI / 2);
                    applyOptFill(rec.key, rec.index, 'offsetY');
                    ctx.font = '400 13px Prompt,sans-serif';
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(`${valY} cm`, 0, -14);
                    ctx.restore();

                } else if (measureYDir === 'top') {
                    const yObjTop = topY;
                    const yBoxTop = boxUsed.y;
                    if (hasNeighborAbove && !isCenterFrom) {
                        
                        // --- ส่วนที่แก้ไข Logic การหลบ Option ---
                        const safeBuffer = toPx(4); // ระยะห่าง 3cm
                        let detourX;
                        
                        if (isRightSide) {
                            // ถ้าจัดชิดขวา: ให้โยงหลบไปทางซ้าย โดยลบ 6px (ระยะเส้นบอกความสูง) และลบ safeBuffer(3cm)
                            detourX = neighborAbove.draw.leftX - 6 - safeBuffer;
                        } else {
                            // ถ้าจัดชิดซ้าย (เคสของคุณ): ให้โยงหลบไปทางขวา 
                            // โดยอิงจาก ขอบซ้าย Option บน + ความกว้าง Option บน + 6px (ระยะเส้นบอกความสูง) + safeBuffer(3cm)
                            detourX = neighborAbove.draw.leftX + neighborAbove.draw.rw + 6 + safeBuffer;
                        }
                        // ------------------------------------

                        ctx.save(); ctx.setLineDash([4, 3]);
                        drawPlainLineH(leftX + rw / 2, detourX, yObjTop, cIn); // ลากเส้นประแนวนอนไปหาจุด Detour
                        ctx.restore();
                        drawArrowV(yBoxTop, yObjTop, detourX, cIn, rec.key, rec.index); // ลากเส้นตั้งจากจุด Detour
                        ctx.save();
                        const textY = yBoxTop + (yObjTop - yBoxTop) / 2;
                        ctx.translate(detourX, textY);
                        ctx.rotate(isRightSide ? -Math.PI / 2 : Math.PI / 2);
                        applyOptFill(rec.key, rec.index, 'offsetY');
                        ctx.font = '400 13px Prompt,sans-serif';
                        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                        ctx.fillText(`${valY} cm`, 0, -14);
                        ctx.restore();
                    } else {
                        drawArrowV(yBoxTop, yObjTop, arrowX, cIn, rec.key, rec.index);
                        const textY = yBoxTop + (yObjTop - yBoxTop) / 2;
                        drawYText(`${valY} cm`, textY);
                    }
                } else {
                    const yObjBottom = topY + rh;
                    const yRefBottom = refBottomY;
                    drawArrowV(yObjBottom, yRefBottom, arrowX, cIn, rec.key, rec.index);
                    const textY = yObjBottom + (yRefBottom - yObjBottom) / 2;
                    drawYText(`${valY} cm`, textY);
                }
            }

            if (valX != null && !cfg.hideDim) {
                const isSmall = (valX < 6);
                const baseLineY = topY + rh / 2;
                const yForXLine = isRotated ? baseLineY : targetLineY;
                const spaceAbove = yForXLine - boxUsed.y;
                const textHeightApprox = 25;
                let textOnBottom = false;
                if (spaceAbove < textHeightApprox) textOnBottom = true;

                const getTextYOffset = (baseOffset) => {
                    if (textOnBottom) {
                        if (baseOffset === -8) return 18;
                        if (baseOffset === -14) return 14;
                        if (baseOffset === -28) return 28;
                        return 18;
                    }
                    return baseOffset;
                };

                if (showLeaderLine && yForXLine !== baseLineY) {
                    ctx.save(); ctx.setLineDash([4, 3]); ctx.strokeStyle = cIn; ctx.lineWidth = 1.4; ctx.beginPath();
                    const holeSideX = (measureDir === 'right') ? (leftX + rw) : leftX;
                    const holeCenterY = topY + rh / 2;
                    ctx.moveTo(holeSideX, holeCenterY); ctx.lineTo(holeSideX, targetLineY); ctx.stroke(); ctx.restore();
                }

                const drawXLabel = (tx) => {
                    applyOptFill(rec.key, rec.index, 'offsetX');
                    ctx.font = '400 13px Prompt,sans-serif';
                    ctx.textAlign = 'center';
                    if (isDodging && !isRotated) {
                        ctx.textBaseline = 'top';
                        if (isSmall) { ctx.fillText(`${valX}`, tx, yForXLine + 4); ctx.fillText(`cm`, tx, yForXLine + 16); }
                        else { ctx.fillText(`${valX} cm`, tx, yForXLine + 4); }
                    } else {
                        if (isSmall) {
                            ctx.textBaseline = 'middle';
                            ctx.fillText(`${valX}`, tx, yForXLine + getTextYOffset(-28));
                            ctx.fillText(`cm`, tx, yForXLine + getTextYOffset(-14));
                        } else {
                            ctx.textBaseline = textOnBottom ? 'top' : 'alphabetic';
                            ctx.fillText(`${valX} cm`, tx, yForXLine + getTextYOffset(-8));
                        }
                    }
                    resetCtxFx();
                };

                if (measureDir === 'left') {
                    const xA = boxUsed.x; const xB = leftX;
                    drawArrowH(xA, xB, yForXLine, cIn, rec.key, rec.index);
                    drawXLabel(xA + (xB - xA) / 2);
                } else if (measureDir === 'right') {
                    const xA = leftX + rw; const xB = boxUsed.x + boxUsed.w;
                    drawArrowH(xA, xB, yForXLine, cIn, rec.key, rec.index);
                    drawXLabel(xA + (xB - xA) / 2);
                } else {
                    const xA = boxUsed.x, xB = leftX;
                    drawArrowH(xA, xB, yForXLine, cIn, rec.key, rec.index);
                    drawXLabel(xA + (xB - xA) / 2);
                }
            }

            rec.draw.targetLineY = targetLineY;
            ctx.restore();
        });

        arr.forEach((rec, i) => {
            const d = rec.draw;
            ctx.save(); pathHole(d); ctx.globalCompositeOperation = 'destination-out'; ctx.fillStyle = '#000'; ctx.fill(); ctx.restore();
            if (typeof paintRedLegsInsideHole === 'function') paintRedLegsInsideHole(rec);
            ctx.save(); pathHole(d); ctx.globalCompositeOperation = 'source-over'; ctx.setLineDash([]);
            ctx.strokeStyle = violIdx[bk].has(i) ? RED : cIn;
            ctx.lineWidth = violIdx[bk].has(i) ? 2.5 : 2;
            ctx.stroke(); ctx.restore();
        });
    });
}


function scanLegColors(ctx, x, y, w, h, isCircle) {
    if (!ctx || w <= 0 || h <= 0) return false;
    const imgData = ctx.getImageData(Math.floor(x), Math.floor(y), Math.floor(w), Math.floor(h));
    const data = imgData.data;
    for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const a = data[i + 3];
        if (a < 50) continue;
        if (isCircle) {
            const px = (i / 4) % w;
            const py = Math.floor((i / 4) / w);
            const cx = w / 2;
            const cy = h / 2;
            if (Math.pow(px - cx, 2) + Math.pow(py - cy, 2) > Math.pow(w / 2, 2)) {
                continue;
            }
        }
        if ((r === 254 && g === 50 && b === 50) || 
			(r === 255 && g === 51 && b === 51) || 
            (r === 210 && g === 6 && b === 6) || 
            (r === 204 && g === 0 && b === 0)) {
            return true;
        }
    }
    return false;
}
window.draw2DDebugRuler = function(ctx, startX, startY, logicW, logicH, sc, label) {
    ctx.save();
    
    // Config สี
    const color = (label === 'MAIN') ? '#00FF00' : '#FF0000'; 
    ctx.strokeStyle = color;
    ctx.lineWidth = 1;
    ctx.strokeRect(startX, startY, logicW * sc, logicH * sc);

    // Label
    ctx.fillStyle = color;
    ctx.font = "bold 14px Arial";
    ctx.fillText(label, startX + 5, startY + 15);

    // ฟังก์ชันย่อยวาดขีด
    const drawTick = (val, maxVal, isVertical, offsetPx, edgeType) => {
        const isTen = (val % 10 === 0);
        const isCenter = (Math.abs(val - maxVal / 2) < 0.1);
        const len = isCenter ? 15 : (isTen ? 10 : 5);
        const posPx = val * sc;
        
        ctx.beginPath();
        let txtX, txtY, align, baseline;

        if (!isVertical) { // แนวนอน
            const px = startX + posPx;
            const py = startY + offsetPx;
            const dir = (edgeType === 'top') ? -1 : 1;
            ctx.moveTo(px, py);
            ctx.lineTo(px, py + (len * dir));
            txtX = px; txtY = py + ((len + 3) * dir);
            align = "center"; baseline = (edgeType === 'top') ? "bottom" : "top";
        } else { // แนวตั้ง
            const px = startX + offsetPx;
            const py = startY + posPx;
            const dir = (edgeType === 'left') ? -1 : 1;
            ctx.moveTo(px, py);
            ctx.lineTo(px + (len * dir), py);
            txtX = px + ((len + 3) * dir); txtY = py;
            align = (edgeType === 'left') ? "right" : "left"; baseline = "middle";
        }

        if (isCenter) { ctx.strokeStyle = '#D32F2F'; ctx.lineWidth = 2; }
        else if (isTen) { ctx.strokeStyle = 'rgba(0,0,0,0.8)'; ctx.lineWidth = 1; }
        else { ctx.strokeStyle = 'rgba(0,0,0,0.2)'; ctx.lineWidth = 0.5; }
        ctx.stroke();

        if (isTen || isCenter) {
            ctx.fillStyle = isCenter ? '#D32F2F' : 'black';
            ctx.font = isCenter ? "bold 12px Arial" : "10px Arial";
            ctx.textAlign = align; ctx.textBaseline = baseline;
            ctx.fillText(isCenter ? "CNTR" : Math.round(val), txtX, txtY);
        }
    };

    // วาดขีดรอบด้าน
    for (let i = 0; i <= logicW; i += 2) {
        drawTick(i, logicW, false, 0, 'top');
        drawTick(i, logicW, false, logicH * sc, 'bottom');
    }
    for (let j = 0; j <= logicH; j += 2) {
        drawTick(j, logicH, true, 0, 'left');
        drawTick(j, logicH, true, logicW * sc, 'right');
    }
    ctx.restore();
};



window.drawLegsForScan = function(ctx, sc, rectMain, rectArm) {
    if (!ctx) return;
    const byId = (id) => document.getElementById(id);
    const type = (byId('dpb-type')?.value || '').trim().toLowerCase();
    
    const loadImg = (url) => {
        if (!url) return null;
        if (window.__desk_img_cache && window.__desk_img_cache[url]) return window.__desk_img_cache[url];
        return null;
    };

    ctx.save();
    
    try {
        if (type === 'custom') {
            const Lcm = +byId('dpb-ml').value || 0;
            const Wcm = +byId('dpb-mw').value || 0;
            const A = getLegAssetsBySelection();
            const imgL = loadImg(A.left);
            const imgC = loadImg(A.center);
            const imgR = loadImg(A.right);
            
            if (imgL && imgC && imgR) {
                const layout = computeLegLayoutRectDesk({ x: rectMain.x, y: rectMain.y, w: rectMain.w, h: rectMain.h, sc, Lcm, Wcm });
                ctx.drawImage(imgR, layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h);
                ctx.save();
                const cropW = Math.max(0, layout.crop.rightX - layout.crop.leftX);
                if(cropW > 0){
                    ctx.beginPath(); ctx.rect(layout.crop.leftX, rectMain.y, cropW, rectMain.h); ctx.clip();
                    ctx.drawImage(imgC, layout.centerRect.x, layout.centerRect.y, layout.centerRect.w, layout.centerRect.h);
                }
                ctx.restore();
                ctx.drawImage(imgL, layout.leftRect.x, layout.leftRect.y, layout.leftRect.w, layout.leftRect.h);
            }
        } 
        else if (type === 'l3' && rectArm) {
            const sideSel = (byId('dpb-aside')?.value || 'right').toLowerCase();
            const layout = computeLegLayoutL3Rects_SMART({ rect1: rectMain, rect2: rectArm, sc, Lcm: +byId('dpb-ml').value, side: sideSel });
            
            if (layout) {
                const pack = getL3AssetsAndDims(sideSel);
                const A = pack.A;
                const drawPart = (key, rect) => {
                    if(!rect) return;
                    const url = A[key];
                    if(!url && key.includes('_v3')) return; 
                    const img = loadImg(url) || loadImg(A[key.replace('_v3','')]);
                    if(img) ctx.drawImage(img, rect.x, rect.y, rect.w, rect.h);
                };

                if (sideSel === 'left') {
                    drawPart('right', layout.rightRect);
                    drawPart('bottomLeft', layout.bottomLeft);
                    drawPart('topLeft', layout.topLeft); 
                    drawPart('topCenter', layout.topCenter); 
                    drawPart('centerLeft', layout.centerLeft);
                } else {
                    drawPart('left', layout.leftRect);
                    drawPart('bottomRight', layout.bottomRight);
                    drawPart('topRight', layout.topRight);
                    drawPart('topCenter', layout.topCenter);
                    drawPart('centerRight', layout.centerRight);
                }
            }
        }
        else if (type === 'l2' && rectArm) {
            const sideSel = (byId('dpb-aside')?.value || 'right').toLowerCase();
            const Lcm = +byId('dpb-ml').value || 0;
            const dims = LEG_DIMS_L2_CM;
            
            let layout = computeLegLayoutL2Rect1({ x: rectMain.x, y: rectMain.y, w: rectMain.w, h: rectMain.h, sc, Lcm, side: sideSel, dims });
            if (l2_needsV2(layout, rectArm, sideSel)) {
                layout = computeLegLayoutL2Rect1_V2({ x: rectMain.x, y: rectMain.y, w: rectMain.w, h: rectMain.h, sc, Lcm, side: sideSel, dims, rect2: rectArm, baseCrop: layout.crop });
            }

            const colorRaw = getLegColorFromSelection();
            const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
            const A_L2 = LEG_ASSETS_L2[color] || LEG_ASSETS_L2.white;
            const l2Assets = (sideSel === 'right') 
                ? { left:A_L2.left, right:A_L2.rightL, center:A_L2.center } 
                : { left:A_L2.leftL, right:A_L2.right, center:A_L2.center };

            const iL = loadImg(l2Assets.left);
            const iR = loadImg(l2Assets.right);
            const iC = loadImg(l2Assets.center);

            if(iC && layout.centerRect) ctx.drawImage(iC, layout.centerRect.x, layout.centerRect.y, layout.centerRect.w, layout.centerRect.h);
            if(iR && layout.rightRect)  ctx.drawImage(iR, layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h);
            if(iL && layout.leftRect)   ctx.drawImage(iL, layout.leftRect.x, layout.leftRect.y, layout.leftRect.w, layout.leftRect.h);
        }
        else if (type === 'custom_single') {
             const colorRaw = getLegColorFromSelection();
             const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
             const A = SINGLE_MOTOR_ASSETS[legColor] || SINGLE_MOTOR_ASSETS.white;
             const iR = loadImg(A.right); const iL = loadImg(A.left); const iC = loadImg(A.center);
             const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
             const dims = LEG_DIMS_SINGLE_MOTOR_CM;
             
             const gapLeft = Math.max(5, gaps.A); const gapRight = Math.max(5, gaps.B);
             const rW = dims.right.w*sc, lW = dims.left.w*sc, cW = dims.center.w*sc, cH = dims.center.h*sc;
             const lX = rectMain.x + gapLeft*sc; 
             const rX = rectMain.x + rectMain.w - gapRight*sc - rW;
             const cX = rectMain.x + (rectMain.w - cW)/2;
             const lY = rectMain.y + (rectMain.h - dims.left.h*sc)/2;
             const rY = rectMain.y + (rectMain.h - dims.right.h*sc)/2;
             const cY = rectMain.y + (rectMain.h - cH)/2;

             if(iC) ctx.drawImage(iC, cX, cY, cW, cH);
             if(iL) ctx.drawImage(iL, lX, lY, lW, dims.left.h*sc);
             if(iR) ctx.drawImage(iR, rX, rY, rW, dims.right.h*sc);
        }
        else if (type === 'custom_workspace') {
             const colorRaw = getLegColorFromSelection();
             const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
             const A = WORKSPACE_ASSETS[legColor] || WORKSPACE_ASSETS.white;
             const iR = loadImg(A.right); const iL = loadImg(A.left); const iC = loadImg(A.center);
             const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
             const dims = LEG_DIMS_WORKSPACE_CM;
             
             const gapLeft = Math.max(5, gaps.A); const gapRight = Math.max(5, gaps.B);
             const rW = dims.right.w*sc, lW = dims.left.w*sc, cW = dims.center.w*sc, cH = dims.center.h*sc;
             const lX = rectMain.x + gapLeft*sc; 
             const rX = rectMain.x + rectMain.w - gapRight*sc - rW;
             const cX = rectMain.x + (rectMain.w - cW)/2;
             const lY = rectMain.y + (rectMain.h - dims.left.h*sc)/2;
             const rY = rectMain.y + (rectMain.h - dims.right.h*sc)/2;
             const cY = rectMain.y + (rectMain.h - cH)/2;

             if(iC) ctx.drawImage(iC, cX, cY, cW, cH);
             if(iL) ctx.drawImage(iL, lX, lY, lW, dims.left.h*sc);
             if(iR) ctx.drawImage(iR, rX, rY, rW, dims.right.h*sc);
        }
        else if (type === 'custom_manual') {
             const colorRaw = getLegColorFromSelection();
             const legColor = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
             const A = MANUAL_DESK_ASSETS[legColor] || MANUAL_DESK_ASSETS.white;
             const iR = loadImg(A.right); const iL = loadImg(A.left); const iC = loadImg(A.center);
             const gaps = (typeof window.dpb_getLegGaps === 'function') ? window.dpb_getLegGaps() : { A: 5, B: 5 };
             const dims = LEG_DIMS_MANUAL_CM;
             
             const gapLeft = Math.max(5, gaps.A); const gapRight = Math.max(5, gaps.B);
             const rW = dims.right.w*sc, rH = dims.right.h*sc;
             const lW = dims.left.w*sc, lH = dims.left.h*sc;
             const cW = dims.center.w*sc, cH = dims.center.h*sc;
             const leftX = rectMain.x + gapLeft*sc; 
             const rightX = rectMain.x + rectMain.w - gapRight*sc - rW;
             const centerX = rectMain.x + (rectMain.w - cW)/2;
             const leftY = rectMain.y + (rectMain.h - lH)/2;
             const rightY = rectMain.y + (rectMain.h - rH)/2;
             const centerY = rectMain.y + (rectMain.h - cH)/2;

             if(iC) ctx.drawImage(iC, centerX, centerY, cW, cH);
             if(iL) ctx.drawImage(iL, leftX, leftY, lW, lH);
             if(iR) ctx.drawImage(iR, rightX, rightY, rW, rH);
        }
        else if (type === 'single') {
             const colorRaw = getLegColorFromSelection();
             const color = (colorRaw ? String(colorRaw).toLowerCase() : 'white');
             const A = SINGLE_LEG_ASSETS[color] || SINGLE_LEG_ASSETS.white;
             const imgLeg = loadImg(A.leg);
             if(imgLeg) {
                 const legWcm = pxToCm(imgLeg.naturalWidth);
                 const legHcm = pxToCm(imgLeg.naturalHeight);
                 const legW = cmToPx(legWcm, sc);
                 const legH = cmToPx(legHcm, sc);
                 const legX = rectMain.x + (rectMain.w - legW)/2;
                 const legY = rectMain.y + (rectMain.h - legH)/2;
                 ctx.drawImage(imgLeg, legX, legY, legW, legH);
             }
        }
    } catch(e) { 
        console.warn('Leg Scan Draw Error', e); 
    }

    ctx.globalCompositeOperation = 'source-in';
    ctx.fillStyle = '#fe3232';
    ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    
    ctx.globalCompositeOperation = 'source-over';
    ctx.restore();
};



function ensureL3Rects(rec, sc){
  let rect1 = state?.boxes?.main || null;
  let rect2 = state?.boxes?.arm  || null;
  if (rect1 && rect2) return { rect1, rect2 };
  const t   = (byId('dpb-type')?.value || 'l3').toLowerCase();
  const def = (typeof getTypeDefaults === 'function') ? getTypeDefaults(t) : { mw:70, ml:180, aw:120, al:70, aside:'right' };
  const getNum = (id, key)=>{
    const v = Number(byId(id)?.value);
    if (Number.isFinite(v)) return v;
    return def?.[key];
  };
  const L    = getNum('dpb-ml','ml');   
  const W    = getNum('dpb-mw','mw');  
  const AL   = getNum('dpb-al','al'); 
  const AW   = getNum('dpb-aw','aw');  
  const side = (byId('dpb-aside')?.value || def.aside || 'right').toLowerCase();
  const px   = (cm)=> cm * sc;
  if (rect1 && !rect2){
    rect2 = (side === 'right')
      ? { x: rect1.x + px(L - AL), y: rect1.y, w: px(AL), h: px(AW) }
      : { x: rect1.x,              y: rect1.y, w: px(AL), h: px(AW) };
    return { rect1, rect2 };
  }
  if (!rect1 && rect2){
    rect1 = (side === 'right')
      ? { x: rect2.x - px(L - AL), y: rect2.y, w: px(L), h: px(W) }
      : { x: rect2.x,              y: rect2.y, w: px(L), h: px(W) };
    return { rect1, rect2 };
  }
  if (!rect1 && !rect2){
    if (!rec?.box) return { rect1:null, rect2:null };
    const b   = rec.box;
    const w1  = px(L),  h1 = px(W);  
    const w2  = px(AL), h2 = px(AW);  
    const area = (w,h)=> w*h;
    const dMain = Math.abs(area(b.w, b.h) - area(w1, h1));
    const dArm  = Math.abs(area(b.w, b.h) - area(w2, h2));
    if (dMain <= dArm){
      rect1 = { x:b.x, y:b.y, w:w1, h:h1 };
      rect2 = (side === 'right')
        ? { x: rect1.x + px(L - AL), y: rect1.y, w: px(AL), h: px(AW) }
        : { x: rect1.x,              y: rect1.y, w: px(AL), h: px(AW) };
    } else {
      rect2 = { x:b.x, y:b.y, w:w2, h:h2 };
      rect1 = (side === 'right')
        ? { x: rect2.x - px(L - AL), y: rect2.y, w: px(L), h: px(W) }
        : { x: rect2.x,              y: rect2.y, w: px(L), h: px(W) };
    }
    return { rect1, rect2 };
  }
  return { rect1, rect2 };
}

function DPB_resolveFn(name){
  try { const f = eval(name); if (typeof f === 'function') return f; } catch(_) {}
  const g = (typeof window !== 'undefined') ? window[name] : undefined;
  return (typeof g === 'function') ? g : null;
}

function paintRedLegsInsideHole(rec){
  const d = rec.draw || {};
  const holeL = d.leftX|0, holeT = d.topY|0, holeW = d.rw|0, holeH = d.rh|0;
  const typeNow = (byId('dpb-type')?.value || 'custom').trim().toLowerCase();
  const sc = deskScale();
  ctx.save();
  if (typeof makeHolePath === 'function') {
    makeHolePath(d);
  } else {
    ctx.beginPath();
    if (d.isCircle){
      const r = holeW/2, cx = holeL + r, cy = holeT + r;
      ctx.arc(cx, cy, r, 0, Math.PI*2);
    } else {
      ctx.rect(holeL, holeT, holeW, holeH);
    }
  }
  ctx.clip();
  ctx.globalCompositeOperation = 'source-over';
  ctx.globalAlpha = 1;
  try{
    if (state?.flags?.showLegs === false){
    }
    else if (typeNow === 'l2'){
   let mainRect = state?.boxes?.main || ensureL2MainRect(rec, sc);
if (!mainRect){ ctx.restore(); return; }
      const yCrop = mainRect.y;
      const hCrop = mainRect.h;
      const sideSel = (String(byId('dpb-aside')?.value || 'right').toLowerCase() === 'left') ? 'left' : 'right';
      const color   = (getLegColorFromSelection() || 'white').toLowerCase();
      const A = LEG_ASSETS_L2[color] || LEG_ASSETS_L2.white;
      const assets = (sideSel === 'right')
        ? { left:A.left, right:A.rightL, leftL:A.leftL, rightL:A.rightL, center:A.center }
        : { left:A.leftL,right:A.right,  leftL:A.leftL, rightL:A.rightL, center:A.center };
      const img = {
        left:   loadLegImage(assets.left,   scheduleRedraw),
        right:  loadLegImage(assets.right,  scheduleRedraw),
        leftL:  loadLegImage(assets.leftL,  scheduleRedraw),
        rightL: loadLegImage(assets.rightL, scheduleRedraw),
        center: loadLegImage(assets.center, scheduleRedraw),
      };
      if (!img.left || !img.right || !img.leftL || !img.rightL || !img.center){
        if (window.DPB_DEBUG){
          const ready = Object.fromEntries(Object.entries(img).map(([k,v])=>[k, !!(v && v.complete)]));
          console.log('[L2/Hole] assets not ready', ready);
        }
        ctx.restore(); return;
      }
      const Lcm = +byId('dpb-ml').value || 0;
      const layout = computeLegLayoutL2Rect1({
        x: mainRect.x, y: mainRect.y, w: mainRect.w, h: mainRect.h,
        sc, Lcm, side: sideSel, dims: LEG_DIMS_L2_CM
      });
      if (!layout){ ctx.restore(); return; }
      let cropLeftX  = Math.max(layout.crop.leftX,  mainRect.x, holeL);
      let cropRightX = Math.min(layout.crop.rightX, mainRect.x + mainRect.w, holeL + holeW);
      if (cropRightX <= cropLeftX){
        const mid = holeL + holeW/2;
        cropLeftX  = Math.max(mid - layout.centerRect.w/2, mainRect.x, holeL);
        cropRightX = Math.min(mid + layout.centerRect.w/2, mainRect.x + mainRect.w, holeL + holeW);
      }
      const drawCenterCropped = () => {
        ctx.save();
        const cw = Math.max(0, cropRightX - cropLeftX);
        if (cw > 0){
          ctx.beginPath();
          ctx.rect(cropLeftX, yCrop, cw, hCrop);
          ctx.clip();
          ctx.drawImage(img.center, layout.centerRect.x, layout.centerRect.y, layout.centerRect.w, layout.centerRect.h);
        }
        ctx.restore();
      };
      ctx.save();
      if (sideSel === 'right'){
        ctx.drawImage(img.left,   layout.leftRect.x,  layout.leftRect.y,  layout.leftRect.w,  layout.leftRect.h);
        drawCenterCropped();
        ctx.drawImage(img.rightL, layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h);
      } else {
        ctx.drawImage(img.leftL,  layout.leftRect.x,  layout.leftRect.y,  layout.leftRect.w,  layout.leftRect.h);
        drawCenterCropped();
        ctx.drawImage(img.right,  layout.rightRect.x, layout.rightRect.y, layout.rightRect.w, layout.rightRect.h);
      }
      ctx.restore();
      ctx.save();
      ctx.globalCompositeOperation = 'source-atop';
      ctx.globalAlpha = 0.8;
      ctx.fillStyle = '#ff0000';
      ctx.fillRect(holeL, holeT, holeW, holeH);
      ctx.restore();
    }
else if (typeNow === 'l3'){
  const { rect1, rect2 } = ensureL3Rects(rec, sc);
  if (rect1 && rect2){
    const sideSel = (byId('dpb-aside')?.value || 'right').toLowerCase();
    const { A }   = getL3AssetsAndDims(sideSel);
    const imgs = {};
    Object.keys(A).forEach(k => { imgs[k] = loadLegImage(A[k], scheduleRedraw); });
    const Lcm = +byId('dpb-ml').value || 0;
    const layout = computeLegLayoutL3Rects_SMART({ rect1, rect2, sc, Lcm, side: sideSel });
    ctx.save();
    ctx.globalAlpha = 1;
    if (sideSel === 'left'){
      if (imgs.right)       ctx.drawImage(imgs.right,       layout.rightRect.x,       layout.rightRect.y,       layout.rightRect.w,       layout.rightRect.h);
      if (imgs.bottomLeft) ctx.drawImage(imgs.bottomLeft, layout.bottomLeft.x,      layout.bottomLeft.y,      layout.bottomLeft.w,      layout.bottomLeft.h);
      if (imgs.topLeft)     ctx.drawImage(imgs.topLeft,     layout.topLeft.x,         layout.topLeft.y,         layout.topLeft.w,         layout.topLeft.h);
      if (imgs.topCenter){
        const lX = Math.max(layout.cropTopCenterX.leftX,  rect1.x, holeL);
        const rX = Math.min(layout.cropTopCenterX.rightX, rect1.x + rect1.w, holeL + holeW);
        const cw = Math.max(0, rX - lX);
        if (cw>0){
          ctx.save();
          ctx.beginPath(); ctx.rect(lX, Math.max(rect1.y, holeT), cw, Math.min(rect1.y+rect1.h, holeT+holeH)-Math.max(rect1.y, holeT));
          ctx.clip();
          ctx.drawImage(imgs.topCenter, layout.topCenter.x, layout.topCenter.y, layout.topCenter.w, layout.topCenter.h);
          ctx.restore();
        }
      }
      if (imgs.centerLeft){
        const tY = Math.max(layout.cropCenterLeftY.topY, rect2.y, holeT);
        const bY = Math.min(layout.cropCenterLeftY.botY, rect2.y + rect2.h, holeT + holeH);
        const ch = Math.max(0, bY - tY);
        if (ch>0){
          ctx.save();
          ctx.beginPath(); ctx.rect(Math.max(rect2.x, holeL), tY, Math.min(rect2.x+rect2.w, holeL+holeW)-Math.max(rect2.x, holeL), ch);
          ctx.clip();
          ctx.drawImage(imgs.centerLeft, layout.centerLeft.x, layout.centerLeft.y, layout.centerLeft.w, layout.centerLeft.h);
          ctx.restore();
        }
      }
    } else {
      if (imgs.left)        ctx.drawImage(imgs.left,        layout.leftRect.x,        layout.leftRect.y,        layout.leftRect.w,        layout.leftRect.h);
      if (imgs.bottomRight) ctx.drawImage(imgs.bottomRight,layout.bottomRight.x,     layout.bottomRight.y,     layout.bottomRight.w,     layout.bottomRight.h);
      if (imgs.topRight)    ctx.drawImage(imgs.topRight,   layout.topRight.x,        layout.topRight.y,        layout.topRight.w,        layout.topRight.h);
      if (imgs.topCenter){
        const lX = Math.max(layout.cropTopCenterX.leftX,  rect1.x, holeL);
        const rX = Math.min(layout.cropTopCenterX.rightX, rect1.x + rect1.w, holeL + holeW);
        const cw = Math.max(0, rX - lX);
        if (cw>0){
          ctx.save();
          ctx.beginPath(); ctx.rect(lX, Math.max(rect1.y, holeT), cw, Math.min(rect1.y+rect1.h, holeT+holeH)-Math.max(rect1.y, holeT));
          ctx.clip();
          ctx.drawImage(imgs.topCenter, layout.topCenter.x, layout.topCenter.y, layout.topCenter.w, layout.topCenter.h);
          ctx.restore();
        }
      }
      if (imgs.centerRight){
        const tY = Math.max(layout.cropCenterRightY.topY, rect2.y, holeT);
        const bY = Math.min(layout.cropCenterRightY.botY, rect2.y + rect2.h, holeT + holeH);
        const ch = Math.max(0, bY - tY);
        if (ch>0){
          ctx.save();
          ctx.beginPath(); ctx.rect(Math.max(rect2.x, holeL), tY, Math.min(rect2.x+rect2.w, holeL+holeW)-Math.max(rect2.x, holeL), ch);
          ctx.clip();
          ctx.drawImage(imgs.centerRight, layout.centerRight.x, layout.centerRight.y, layout.centerRight.w, layout.centerRight.h);
          ctx.restore();
        }
      }
    }
    ctx.restore();
    ctx.save();
    ctx.globalCompositeOperation = 'source-atop';
    ctx.globalAlpha = 0.8;
    ctx.fillStyle = '#ff0000';
    ctx.fillRect(holeL, holeT, holeW, holeH);
    ctx.restore();
  }
}
    else {
      const drawSingle = DPB_resolveFn('drawSingleLegLayer');
      const drawCustom = DPB_resolveFn('drawCustomDeskLegsLayer');
	  const drawSingleMotor = DPB_resolveFn('drawSingleMotorLegsLayer');
	  const drawWorkSpace = DPB_resolveFn('drawWorkSpaceLegsLayer');
	  const drawManual = DPB_resolveFn('drawManualDeskLegsLayer');
      if (typeNow === 'single' && rec.box && drawSingle){
        drawSingle({ x:rec.box.x, y:rec.box.y, w:rec.box.w, h:rec.box.h, sc, alphaOverride:1, topClip:{ enable:false } });
      } else if (typeNow === 'custom' && rec.box && drawCustom){
        drawCustom({ x:rec.box.x, y:rec.box.y, w:rec.box.w, h:rec.box.h, sc, alphaOverride:1, topClip:{ enable:false } });
      }
      else if (typeNow === 'custom_single' && rec.box && drawSingleMotor){
        drawSingleMotor({ x:rec.box.x, y:rec.box.y, w:rec.box.w, h:rec.box.h, sc, alphaOverride:1, topClip:{ enable:false } });
      }
	  else if (typeNow === 'custom_workspace' && rec.box && drawWorkSpace){
        drawWorkSpace({ x:rec.box.x, y:rec.box.y, w:rec.box.w, h:rec.box.h, sc, alphaOverride:1, topClip:{ enable:false } });
      }
	  else if (typeNow === 'custom_manual' && rec.box && drawManual){
        drawManual({ x:rec.box.x, y:rec.box.y, w:rec.box.w, h:rec.box.h, sc, alphaOverride:1, topClip:{ enable:false } });
      }
      ctx.save();
      ctx.globalCompositeOperation = 'source-atop';
      ctx.globalAlpha = 0.8;
      ctx.fillStyle = '#ff0000';
      ctx.fillRect(holeL, holeT, holeW, holeH);
      ctx.restore();
    }
  }catch(e){
    console.error('[DPB][ERR] paintRedLegsInsideHole', e);
  }
  ctx.save();
  ctx.globalCompositeOperation = 'destination-over';
  ctx.globalAlpha = 1;
  ctx.fillStyle = (state?.theme?.bg) || '#ffffff';
  ctx.fillRect(holeL, holeT, holeW, holeH);
  ctx.restore();
  ctx.restore();
}

function drawLDeskAt(X, Y, sc, bg){
  const W  = +byId('dpb-mw').value || 60;
  const L  = +byId('dpb-ml').value || 200;
  const AW = +byId('dpb-aw').value || 120;
  const AL = +byId('dpb-al').value || 60;
  const side = byId('dpb-aside').value; 

  const getNum = (id, fb=0) => {
    const el = byId(id); const v = el ? Number(el.value) : fb;
    return Number.isFinite(v) ? v : fb;
  };

  const r_tl_mm     = getNum('ld_r_tl', 0);
  const r_tr_mm     = getNum('ld_r_tr', 0);
  const r_step_mm   = getNum('ld_r_step', 0);
  const r_br_mm     = getNum('ld_r_br', 0);
  const r_arm_bl_mm = getNum('ld_r_armbl', 0);
  const r_arm_br_mm = getNum('ld_r_armbr', 0);
  const r_in_mm     = getNum('dpb-rInner', 0);

  const px = v => v*sc;
  const R  = mm => (mm/10)*sc; 
  const pat = patFor(byId('dpb-top-color').value);

  const rect1 = { x:X, y:Y, w:px(L), h:px(W),
    tl:R(r_tl_mm), tr:R(r_tr_mm), bl:R(r_step_mm), br:R(r_br_mm) };
  const rect2 = { x:(side==='right') ? (X + px(L-AL)) : X, y:Y, w:px(AL), h:px(AW) };
  const r2 = (side === 'right')
    ? { tl:0, tr:R(r_tr_mm), br:R(r_arm_br_mm), bl:R(r_arm_bl_mm) }
    : { tl:R(r_tl_mm), tr:0, br:R(r_arm_br_mm), bl:R(r_arm_bl_mm) };

  setBoxes({
    rect1: { x:rect1.x, y:rect1.y, w:rect1.w, h:rect1.h }, 
    main : { x:rect1.x, y:rect1.y, w:rect1.w, h:rect1.h },
    arm  : { x:rect2.x, y:rect2.y, w:rect2.w, h:rect2.h }
  });

  ctx.save();
  ctx.fillStyle = pat ? pat : '#fff';
  ctx.beginPath();

  if (side === 'right') {
    const p_TL    = { x: X,          y: Y };
    const p_TR    = { x: X + px(L),  y: Y };
    const p_ArmBR = { x: X + px(L),  y: Y + px(AW) };
    const p_ArmBL = { x: X + px(L-AL), y: Y + px(AW) };
    const p_Inner = { x: X + px(L-AL), y: Y + px(W) };
    const p_Step  = { x: X,          y: Y + px(W) };

    ctx.moveTo(p_TL.x, p_TL.y + R(r_tl_mm));
    ctx.arcTo(p_TL.x, p_TL.y, p_TR.x, p_TR.y, R(r_tl_mm));
    ctx.arcTo(p_TR.x, p_TR.y, p_ArmBR.x, p_ArmBR.y, R(r_tr_mm));
    ctx.arcTo(p_ArmBR.x, p_ArmBR.y, p_ArmBL.x, p_ArmBL.y, R(r_arm_br_mm));
    ctx.arcTo(p_ArmBL.x, p_ArmBL.y, p_Inner.x, p_Inner.y, R(r_arm_bl_mm));
    ctx.arcTo(p_Inner.x, p_Inner.y, p_Step.x, p_Step.y, R(r_in_mm));
    ctx.arcTo(p_Step.x, p_Step.y, p_TL.x, p_TL.y, R(r_step_mm));
  } else {
    const p_TL    = { x: X,         y: Y };
    const p_TR    = { x: X + px(L), y: Y };
    const p_BR    = { x: X + px(L), y: Y + px(W) };
    const p_Inner = { x: X + px(AL), y: Y + px(W) };
    const p_ArmBR = { x: X + px(AL), y: Y + px(AW) };
    const p_ArmBL = { x: X,         y: Y + px(AW) };

    ctx.moveTo(p_TL.x, p_TL.y + R(r_tl_mm));
    ctx.arcTo(p_TL.x, p_TL.y, p_TR.x, p_TR.y, R(r_tl_mm));
    ctx.arcTo(p_TR.x, p_TR.y, p_BR.x, p_BR.y, R(r_tr_mm));
    ctx.arcTo(p_BR.x, p_BR.y, p_Inner.x, p_Inner.y, R(r_br_mm));
    ctx.arcTo(p_Inner.x, p_Inner.y, p_ArmBR.x, p_ArmBR.y, R(r_in_mm));
    ctx.arcTo(p_ArmBR.x, p_ArmBR.y, p_ArmBL.x, p_ArmBL.y, R(r_arm_br_mm));
    ctx.arcTo(p_ArmBL.x, p_ArmBL.y, p_TL.x, p_TL.y, R(r_arm_bl_mm));
  }

  ctx.closePath();
  ctx.fill();
  ctx.restore();

  let cutX, cutY, cutW, cutH, cutR;
  {
    const yBottomMain = rect1.y + rect1.h;
    const rect1R = rect1.x + rect1.w;
    const rect2R = rect2.x + rect2.w;
    const rect2B = rect2.y + rect2.h;
    const gapX0  = (side === 'right') ? Math.max(0, rect2.x - rect1.x) : Math.max(0, rect1R - rect2R);
    const gapY0  = Math.max(0, rect2B - yBottomMain);
    const rPxRaw = R(r_in_mm);
    const rPx    = Math.max(0, Math.min(rPxRaw, Math.min(gapX0, gapY0)));
    const baseGapX = gapX0, baseGapY = gapY0;
    let rect4 = { w: baseGapX*2, h: baseGapY*2, x: (side==='right') ? (rect2.x - baseGapX*2) : (rect2R), y: yBottomMain };
    rect4 = { x:Math.round(rect4.x), y:Math.round(rect4.y), w:Math.round(rect4.w), h:Math.round(rect4.h) };
    const r4 = (side==='right') ? { tl:0, tr:rPx, br:0, bl:0 } : { tl:rPx, tr:0, br:0, bl:0 };
    cutX = (side==='right') ? rect1.x : rect2R;
    cutY = yBottomMain;
    cutW = baseGapX;
    cutH = baseGapY;
    cutR = r4;
  }

  try{
    const typeNow = (byId('dpb-type')?.value || '').trim();
    if (typeNow === 'l2') {
      const yCrop = Math.min(rect1.y, rect2.y);
      const hCrop = Math.max(rect1.y+rect1.h, rect2.y+rect2.h) - yCrop;
      DPB_resolveFn('drawL2LegsLayer')({ rect1, rect2, x:rect1.x, y:rect1.y, w:rect1.w, h:rect1.h, sc, side, yCrop, hCrop });
    } else if (typeNow === 'l3') {
      DPB_resolveFn('drawL3LegsLayer')({ rect1, rect2, sc, side });
    }
  }catch(_){}

  const xL = X, xR = X + px(L);
  const yT = Y, yW = Y + px(W), yB = Y + px(AW);
  const armEdge = (side==='right') ? rect2.x : (rect2.x + rect2.w);

  dimH(xL, xR, yT - 24, `${L} cm`, 'up', 'above', { gapPx: 34, dimKey: 'length' });

  const yAL = yB + 22;
  if(side === 'right') dimH(armEdge, xR,  yAL, `${AL} cm`, 'down', 'below', { gapPx: 32, dimKey: 'al' });
  else                 dimH(xL, armEdge,  yAL, `${AL} cm`, 'down', 'below', { gapPx: 32, dimKey: 'al' });

  const xAW = (side==='left') ? (xL-28) : (xR+28);
  dimV(yT, yB, xAW, `${AW} cm`, (side==='left' ? -20 : +25), (side==='left' ? 'right' : 'left'), {
    rotateText:true, clockwise:false, textDy:0, textDx:0, dimKey:'aw'
  });

  function getHorizontalPlacement(){
    const el = byId('dpb-halign') || byId('dpb-hplacement') || byId('dpb-option-halign');
    const v = el?.value?.toLowerCase();
    return (v==='left' || v==='right') ? v : null;
  }
  const hPlace = getHorizontalPlacement();
  let xWline, textPosForW, xOffsetForW;
  if(hPlace==='left'){ xWline=xR+28; textPosForW='left';  xOffsetForW=+28; }
  else if(hPlace==='right'){ xWline=xL-28; textPosForW='right'; xOffsetForW=-28; }
  else { xWline=(side==='left')?(xR+28):(xL-28); textPosForW=(side==='left')?'left':'right'; xOffsetForW=(side==='left')?+28:-28; }

  dimV(yT, yW, xWline, `${W} cm`, xOffsetForW, textPosForW, {
    rotateText:true, clockwise:false, dimKey:'width'
  });

  labelCornerR(xL, yT, r_tl_mm, 'tl', sc);
  labelCornerR(xR, yT, r_tr_mm, 'tr', sc);

  if (side === 'right') {
    labelCornerR(xL,                yW, r_step_mm,   'stepL', sc);
    labelCornerR(rect2.x + rect2.w, yB, r_arm_br_mm, 'armR',  sc);  /* แก้จาก 'br' → 'armR' */
    labelCornerR(armEdge,           yB, r_arm_bl_mm, 'armL',  sc);
  } else {
    labelCornerR(xR,      yW, r_br_mm,     'br',   sc);
    labelCornerR(rect2.x, yB, r_arm_bl_mm, 'armL', sc);              /* แก้จาก 'bl' → 'armL' */
    labelCornerR(armEdge, yB, r_arm_br_mm, 'armR', sc);
  }

  drawInnerGuide({x:cutX, y:cutY, w:cutW, h:cutH, tl:cutR.tl, tr:cutR.tr}, side, sc);

  const totalBox = { x:X, y:Y, w:px(L), h:px(AW) };
  drawOptions(totalBox, bg, sc);

  const meas = measureInfoGrid();
  const headerY = 30;
  const canvasCenterX = canvas.width / 2;
  const centerOffset = (rect1.w - rect2.w) / 2;
  let topCenterX;
  if (side === 'right') { topCenterX = rect2.x - centerOffset; }
  else                  { topCenterX = (rect2.x + rect2.w) + centerOffset; }

  let topY;
  const diffH = rect2.h - rect1.h;
  if (diffH > 0) {
    const midGapY = rect1.y + rect1.h + (diffH / 2);
    topY = Math.round(midGapY - (meas.height / 2) + px(5));
  } else {
    topY = Math.round(rect1.y + rect1.h + px(25));
  }

  drawInfoOverlayOnDesk(meas, {
    headerY:       headerY,
    headerCenterX: canvasCenterX,
    topY:          topY,
    topCenterX:    topCenterX
  });
}
							 
function _dbgRect(r, color='#00e5ff', label='rect') {
  if (!r) return;
  const x = Math.round(r.x) + .5;
  const y = Math.round(r.y) + .5;
  const w = Math.round(r.w);
  const h = Math.round(r.h);
  ctx.save();
  ctx.setLineDash([6,4]);
  ctx.lineWidth = 1.5;
  ctx.strokeStyle = color;
  ctx.strokeRect(x, y, w, h);
  ctx.restore();
  ctx.save();
  ctx.globalAlpha = 0.08;
  ctx.fillStyle = color;
  ctx.fillRect(x-.5, y-.5, w, h);
  ctx.restore();
  ctx.save();
  ctx.font = '500 12px Prompt, sans-serif';
  ctx.fillStyle = color;
  const cap = `${label}  x=${Math.round(r.x)}, y=${Math.round(r.y)}, w=${w}, h=${h}`;
  ctx.fillText(cap, Math.max(6, x + 6), y - 6);
  ctx.restore();
}

function ensureState(){ return (window.state = window.state || {}); }

function setBoxes(obj){
  var ST = ensureState();
  ST.boxes = obj ? JSON.parse(JSON.stringify(obj)) : null; 
}

function _dist(a,b){ const dx=a.x-b.x, dy=a.y-b.y; return Math.hypot(dx,dy); }

function _clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

function _pointRectDist(px, py, rx, ry, rw, rh){
  const cx = _clamp(px, rx, rx+rw);
  const cy = _clamp(py, ry, ry+rh);
  return Math.hypot(px-cx, py-cy);
}

function _minGapPx(a, b) {
    const A = a.draw, B = b.draw;
    const xA1 = A.leftX, xA2 = A.leftX + A.rw;
    const xB1 = B.leftX, xB2 = B.leftX + B.rw;
    const yA1 = A.topY,  yA2 = A.topY  + A.rh;
    const yB1 = B.topY,  yB2 = B.topY  + B.rh;

    const gapX = Math.max(xB1 - xA2, xA1 - xB2, 0);
    const gapY = Math.max(yB1 - yA2, yA1 - yB2, 0);

    /* ถ้าซ้อนกันทั้ง X และ Y → gap = 0
       ถ้าแยกกันแค่แกนเดียว → ใช้ระยะของแกนนั้น */
    if (gapX === 0 && gapY === 0) return 0;
    if (gapX === 0) return gapY;
    if (gapY === 0) return gapX;
    return Math.sqrt(gapX * gapX + gapY * gapY);
}

function drawRectAt(x, y, sc, bg){
  const L = +byId('dpb-ml').value || 190;
  const W = +byId('dpb-mw').value || 60;
  const w = FIXED_DRAW_LEN;
  const h = W * sc;
  const rmm = {
    tl: +byId('r_rect_tl').value || 0, tr: +byId('r_rect_tr').value || 0,
    bl: +byId('r_rect_bl').value || 0, br: +byId('r_rect_br').value || 0
  };
  const rTL = (rmm.tl / 10) * sc, rTR = (rmm.tr / 10) * sc, rBL = (rmm.bl / 10) * sc, rBR = (rmm.br / 10) * sc;
  const pat = patFor(byId('dpb-top-color').value);
  const deskType = byId('dpb-type')?.value || 'custom';
  
  setBoxes({ main:{ x, y, w, h }, arm:null });
  
  const showLegs = (window.state && window.state.flags && window.state.flags.showLegs !== undefined) 
                    ? window.state.flags.showLegs 
                    : true;

  if (showLegs){
    const drawArgs = { x, y, w, h, sc, alphaOverride:1, topClip:{enable:false} };
    if (deskType === 'single') DPB_resolveFn('drawSingleLegLayer')(drawArgs);
    else if (deskType === 'custom') DPB_resolveFn('drawCustomDeskLegsLayer')(drawArgs);
    else if (deskType === 'custom_single') DPB_resolveFn('drawSingleMotorLegsLayer')(drawArgs);
	else if (deskType === 'custom_workspace') DPB_resolveFn('drawWorkSpaceLegsLayer')(drawArgs);
    else if (deskType === 'custom_manual') DPB_resolveFn('drawManualDeskLegsLayer')(drawArgs);
  }

  ctx.save();
  ctx.fillStyle = pat ? pat : '#fff';
  
  // ใช้ฟังก์ชันใหม่ fillSmartRoundedRect แทน
  fillSmartRoundedRect(ctx, x, y, w, h, rTL, rTR, rBR, rBL);
  
  ctx.restore();

  if (showLegs){
    const drawArgsClip = { x, y, w, h, sc, topClip:{ enable:true, x, y, w, h, radii:[rTL, rTR, rBR, rBL] } };
    if (deskType === 'single') DPB_resolveFn('drawSingleLegLayer')(drawArgsClip);
    else if (deskType === 'custom') DPB_resolveFn('drawCustomDeskLegsLayer')(drawArgsClip);
    else if (deskType === 'custom_single') DPB_resolveFn('drawSingleMotorLegsLayer')(drawArgsClip);
	else if (deskType === 'custom_workspace') DPB_resolveFn('drawWorkSpaceLegsLayer')(drawArgsClip);
    else if (deskType === 'custom_manual') DPB_resolveFn('drawManualDeskLegsLayer')(drawArgsClip);
  }

dimH(x, x + w, y - 28, `${L} cm`, 'up', 'above', { gapPx: 24, dimKey: 'length' });
dimV(y, y + h, x + w + 28, `${W} cm`, 20, 'center', { rotateText:true, clockwise:false, dimKey: 'width' });

  labelCornerR(x, y, rmm.tl, 'tl', sc);
  labelCornerR(x + w, y, rmm.tr, 'tr', sc);
  labelCornerR(x + w, y + h, rmm.br, 'br', sc);
  labelCornerR(x, y + h, rmm.bl, 'bl', sc);

  const meas = measureInfoGrid();
  const headerY = 30; 
  const canvasCenterX = canvas.width / 2;
  const topY = y + h + 15; 
  const topCenterX = x + (w/2);

  drawInfoOverlayOnDesk(meas, {
      headerY: headerY,
      headerCenterX: canvasCenterX,
      topY: topY,
      topCenterX: topCenterX
  });

  drawOptions({x, y, w, h}, bg, sc);
}

const cornerInputMap = {
  'r_rect_tl':  'r_tl',
  'r_rect_tr':  'r_tr',
  'r_rect_bl':  'r_bl',
  'r_rect_br':  'r_br',
  'ld_r_tl':    'r_tl',
  'ld_r_tr':    'r_tr',
  'ld_r_step':  'r_step',
  'ld_r_br':    'r_br',
  'ld_r_armbl': 'r_armbl',
  'ld_r_armbr': 'r_armbr',
  'dpb-rInner': 'r_inner',
};

Object.entries(cornerInputMap).forEach(([id, key]) => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('focus', () => startDimPulse(key));
  el.addEventListener('blur',  () => stopDimPulse());
});


function labelCornerR(x, y, rmm, pos='tl', sc=1){
  const c = getOutColor();
  const padTop = 26, padBottom = 28, padSide = 32;
  const cfg = {
    tl:    { dx:-padSide,   dy:-padTop,      align:'right' },
    tr:    { dx:+padSide,   dy:-padTop,      align:'left'  },
    br:    { dx:+padSide,   dy:+padBottom,   align:'left'  },
    bl:    { dx:-padSide,   dy:+padBottom,   align:'right' },
    stepR: { dx:+padSide+6, dy:+padBottom-2, align:'left'  },
    stepL: { dx:-padSide-6, dy:+padBottom-2, align:'right' },
    armR:  { dx:+padSide+6, dy:+padBottom,   align:'left'  },
    armL:  { dx:-padSide-6, dy:+padBottom,   align:'right' },
  };
  const o = cfg[pos] || cfg.tl;

  const posToDimKey = {
    tl:    'r_tl',
    tr:    'r_tr',
    bl:    'r_bl',
    br:    'r_br',
    stepL: 'r_step',
    stepR: 'r_step',
    armL:  'r_armbl',
    armR:  'r_armbr',
  };
  const dimKey   = posToDimKey[pos] || null;
  const isActive = !!(dimKey && window._dpbDimFocus === dimKey);
  const pulse    = isActive ? (window._dpbDimPulse ?? 1) : 1;
  const drawC    = isActive ? '#ff2020' : c;

  const val  = Number(rmm);
  const text = (val === 0) ? 'เหลี่ยม' : `R${val}`;
  const textX = x + o.dx;
  const textY = y + o.dy;

  ctx.save();
  ctx.fillStyle    = drawC;
  ctx.font         = '400 14px Prompt,sans-serif';
  ctx.textAlign    = o.align;
  ctx.textBaseline = 'middle';
  ctx.globalAlpha  = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor  = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur   = isActive ? (14 * (1 - pulse)) : 0;
  ctx.fillText(text, textX, textY);
  ctx.restore();

  drawCornerArrowFromCorner(x, y, textX, textY, drawC, val, sc, isActive, pulse);
}


function drawCornerArrow(fromX, fromY, toX, toY, color){
  ctx.save();
  ctx.strokeStyle = color;
  ctx.lineWidth = 1.4;
  ctx.beginPath();
  ctx.moveTo(fromX, fromY);
  ctx.lineTo(toX, toY);
  ctx.stroke();
  const ang = Math.atan2(toY - fromY, toX - fromX);
  const size = 10;
  ctx.beginPath();
  ctx.moveTo(toX, toY);
  ctx.lineTo(toX - size * Math.cos(ang - Math.PI/6),
              toY - size * Math.sin(ang - Math.PI/6));
  ctx.lineTo(toX - size * Math.cos(ang + Math.PI/6),
              toY - size * Math.sin(ang + Math.PI/6));
  ctx.closePath();
  ctx.fillStyle = color;
  ctx.fill();
  ctx.restore();
}

function drawCornerArrowFromCorner(cornerX, cornerY, textX, textY, color, r_mm=0, sc=1, isActive=false, pulse=1){
  const gapCornerPx = 10;
  const gapTextPx   = 10;
  const headSize    = 10;
  
  const r_px = (r_mm / 10) * sc;
  const angle = Math.atan2(textY - cornerY, textX - cornerX);
  
  let depth = 0;
  if (r_px > 0) {
    const vx = Math.cos(angle), vy = Math.sin(angle);
    const dx = r_px * (vx >= 0 ? 1 : -1);
    const dy = r_px * (vy >= 0 ? 1 : -1);
    const proj = vx * dx + vy * dy;
    const disc = proj * proj - (dx * dx + dy * dy - r_px * r_px);
    if (disc >= 0) depth = proj - Math.sqrt(disc);
  }

  const effectiveGap = gapCornerPx - depth;
  const startX = cornerX + Math.cos(angle) * effectiveGap;
  const startY = cornerY + Math.sin(angle) * effectiveGap;
  const endX   = textX - Math.cos(angle) * gapTextPx;
  const endY   = textY - Math.sin(angle) * gapTextPx;

  ctx.save();
  ctx.strokeStyle = color;
  ctx.fillStyle   = color;
  ctx.lineWidth   = isActive ? (2.5 + 2 * (1 - pulse)) : 1.6;
  ctx.globalAlpha = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur  = isActive ? (14 * (1 - pulse)) : 0;

  ctx.beginPath();
  ctx.moveTo(startX, startY);
  ctx.lineTo(endX, endY);
  ctx.stroke();

  ctx.beginPath();
  ctx.moveTo(endX, endY);
  ctx.lineTo(endX - headSize * Math.cos(angle - Math.PI/6), endY - headSize * Math.sin(angle - Math.PI/6));
  ctx.lineTo(endX - headSize * Math.cos(angle + Math.PI/6), endY - headSize * Math.sin(angle + Math.PI/6));
  ctx.closePath();
  ctx.fill();
  ctx.restore();
}

function drawInnerGuide(cut, side, sc){
  const r    = (side==='right') ? cut.tr : cut.tl;
  const cOut = getOutColor();

  const isActive = !!(window._dpbDimFocus === 'r_inner');
  const pulse    = isActive ? (window._dpbDimPulse ?? 1) : 1;
  const drawC    = isActive ? '#ff2020' : cOut;

  if (!r || r <= 0){
    const cornerX  = (side==='right') ? (cut.x + cut.w) : cut.x;
    const cornerY  = cut.y;
    const textGap  = 6, extraGap = 20, fontSize = 14;
    const textX    = cornerX + (side==='right' ? -(textGap+extraGap) : (textGap+extraGap));
    const textY    = cornerY + (textGap+extraGap);

    ctx.save();
    ctx.fillStyle    = drawC;
    ctx.font         = `400 ${fontSize}px Prompt,sans-serif`;
    ctx.textAlign    = (side==='right' ? 'right' : 'left');
    ctx.textBaseline = 'middle';
    ctx.globalAlpha  = isActive ? (0.5 + 0.5 * pulse) : 1;
    ctx.shadowColor  = isActive ? '#ff0000' : 'transparent';
    ctx.shadowBlur   = isActive ? (14 * (1 - pulse)) : 0;
    ctx.fillText('เหลี่ยม', textX, textY);
    ctx.restore();
    drawCornerArrowFromCorner(cornerX, cornerY, textX, textY, drawC, 0, sc, isActive, pulse);
    return;
  }

  const cx     = (side==='right') ? (cut.x + cut.w - r) : (cut.x + r);
  const cy     = cut.y + r;
  const rGuide = Math.max(10, r - 18);
  const THETA_MID = (side==='right') ? Math.PI*1.75 : Math.PI*1.25;
  const SWEEP  = (60 * Math.PI) / 180;

  ctx.save();
  ctx.strokeStyle = drawC;
  ctx.lineWidth   = isActive ? (2.5 + 2 * (1 - pulse)) : 2;
  ctx.globalAlpha = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur  = isActive ? (14 * (1 - pulse)) : 0;
  ctx.beginPath();
  ctx.arc(cx, cy, rGuide, THETA_MID-SWEEP/2, THETA_MID+SWEEP/2, false);
  ctx.stroke();
  ctx.restore();

  const rmm   = Math.round((r*10)/sc);
  const arcX  = cx + Math.cos(THETA_MID)*rGuide;
  const arcY  = cy + Math.sin(THETA_MID)*rGuide;
  const TEXT_ANGLE = THETA_MID + Math.PI;
  const outPx = 10 * sc;
  const textX = arcX + Math.cos(TEXT_ANGLE) * outPx;
  const textY = arcY + Math.sin(TEXT_ANGLE) * outPx;

  ctx.save();
  ctx.fillStyle    = drawC;
  ctx.font         = '400 14px Prompt,sans-serif';
  ctx.textAlign    = (side==='right' ? 'left' : 'right');
  ctx.textBaseline = 'middle';
  ctx.globalAlpha  = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor  = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur   = isActive ? (14 * (1 - pulse)) : 0;
  ctx.fillText(`R${rmm}`, textX, textY);
  ctx.restore();

  ctx.save();
  ctx.strokeStyle = drawC;
  ctx.lineWidth   = isActive ? (2 + 1.5 * (1 - pulse)) : 1;
  ctx.globalAlpha = isActive ? (0.5 + 0.5 * pulse) : 1;
  ctx.shadowColor = isActive ? '#ff0000' : 'transparent';
  ctx.shadowBlur  = isActive ? (10 * (1 - pulse)) : 0;
  ctx.setLineDash([4, 3]);
  ctx.beginPath();
  ctx.moveTo(cx + Math.cos(THETA_MID)*r,      cy + Math.sin(THETA_MID)*r);
  ctx.lineTo(cx + Math.cos(THETA_MID)*rGuide, cy + Math.sin(THETA_MID)*rGuide);
  ctx.stroke();
  ctx.restore();
}

function drawFooter(){
  const badges = byId('dpb-meta');
  if(!badges) return;
  const t = byId('dpb-type').value;
  const topKey = byId('dpb-top-color').value;
  const legKey = byId('dpb-legs').value;
  const edge   = byId('dpb-edge').value;
  const topName = (state.meta.colors||[]).find(c=>c.key===topKey)?.name || topKey;
  const legName = (state.meta.legs||[]).find(c=>c.key===legKey)?.name || legName;
  const plat    = byId('dpb-platforms').value || '-';
  badges.innerHTML =
    `<span class="b">Top: ${topName}</span>` +
    `<span class="b">Leg: ${legName}</span>` +
    `<span class="b">Edge: ${edge}</span>` +
    `<span class="b">Type: ${t}</span>` +
    `<span class="b">Platform: ${plat}</span>`;
}

function dpb_isSolidTopKey(key){
  return typeof DPB_SOLID_KEYS !== 'undefined' && DPB_SOLID_KEYS.includes(String(key));
}

function dpb_updateSolidwoodVisibility(){
  const typeSel = document.getElementById('dpb-type');
  if (!typeSel) return;
  
  const type = (typeSel.value || '').toLowerCase(); 
  const isL3 = (type === 'l3'); 
  
  // 1. ดึงข้อมูล Group (.dpb-color-section) ทั้งหมด 3 อัน (Solidwood, Whiteboard, Laminate)
  const colorSections = document.querySelectorAll('.dpb-color-section');
  
  let bannedSwatches = [];
  let bannedSections = [];
  
  colorSections.forEach(section => {
    const swatches = Array.from(section.querySelectorAll('.dpb-top-swatch'));
    if (swatches.length === 0) return;
    
    // ตรวจสอบว่ากลุ่มนี้คือ Solidwood หรือไม่
    const isSolidGroup = swatches.some(btn => typeof DPB_SOLID_KEYS !== 'undefined' && DPB_SOLID_KEYS.includes(String(btn.getAttribute('data-key'))));
    
    // ตรวจสอบว่ากลุ่มนี้คือ Whiteboard หรือไม่
    const sectionText = section.innerText.toLowerCase();
    const isWbGroup = sectionText.includes('whiteboard') || sectionText.includes('ไวท์บอร์ด');
    
    // ถ้ารุ่นคือ L3 กลุ่ม Solidwood และ Whiteboard จะต้องถูกรวบเข้า List ที่โดนแบน
    if (isSolidGroup || isWbGroup) {
      bannedSwatches = bannedSwatches.concat(swatches);
      bannedSections.push(section);
    }
  });
  
  // 2. ซ่อนหรือแสดงผล Group (Section) ทั้งก้อน
  bannedSections.forEach(section => {
    if (isL3) {
      section.style.display = 'none';
      section.setAttribute('aria-hidden', 'true');
    } else {
      section.style.display = '';
      section.removeAttribute('aria-hidden');
    }
  });
  
  // ตัวแปรสำหรับเช็คว่ามีการลักไก่เลือกสีที่โดนแบนค้างไว้หรือไม่
  let isBannedActive = false; 
  
  // 3. ซ่อนปุ่มสีที่ถูกแบน และดักจับสถานะ Active
  bannedSwatches.forEach(btn => {
    if (isL3) {
      btn.style.display = 'none';
      btn.setAttribute('aria-hidden', 'true');
      
      // ดักจับ: ถ้าปุ่มที่กำลังจะโดนซ่อน มีสถานะถูกเลือกค้างอยู่
      if (btn.classList.contains('is-active') || btn.getAttribute('aria-selected') === 'true') {
        isBannedActive = true; 
      }

      // บังคับเคลียร์สถานะการเลือกทิ้ง
      btn.classList.remove('is-active');
      btn.setAttribute('aria-selected', 'false');
    } else {
      btn.style.display = '';
      btn.removeAttribute('aria-hidden');
    }
  });
  
  // 4. ระบบป้องกันระดับสูง (Fallback Auto-Click)
  // หากผู้ใช้เลือกสี Solidwood หรือ Whiteboard ค้างไว้ แล้วเพิ่งเปลี่ยนมาเป็นโต๊ะ L3 
  if (isL3 && isBannedActive) {
    // หาปุ่มสีทั้งหมดบนโต๊ะ
    const allSwatches = Array.from(document.querySelectorAll('.dpb-top-swatch'));
    
    // กรองหา "ปุ่มแรก" ที่ปลอดภัย (ไม่ได้อยู่ใน List สีที่โดนแบน)
    const safeSwatches = allSwatches.filter(btn => !bannedSwatches.includes(btn));
    
    if (safeSwatches.length > 0) {
      // ใช้ setTimeout เพื่อหน่วงเวลาให้ DOM เคลียร์ค่าเก่าเสร็จก่อน (ประมาณ 50ms) แล้วค่อยบังคับคลิกสีใหม่
      setTimeout(() => {
        safeSwatches[0].click(); 
      }, 50);
    }
  }
}

// ดักจับการเปลี่ยนแปลงจาก Dropdown
document.addEventListener('change', function(e){
  if (e.target && e.target.id === 'dpb-type'){
    dpb_updateSolidwoodVisibility();
  }
});

// ดักจับการคลิกที่การ์ดเลือกรุ่นโต๊ะ (Type Tiles)
const typeTiles = document.getElementById('dpb-type-tiles');
if (typeTiles){
  typeTiles.addEventListener('click', function(e){
    const btn = e.target.closest('.dpb-type-card[data-value]');
    if (!btn) return;
    
    // หน่วงเวลาเล็กน้อยเพื่อให้ระบบหลักเปลี่ยนค่า type ให้เสร็จก่อนตรวจสอบ
    setTimeout(dpb_updateSolidwoodVisibility, 10);
  });
}

// ตรวจสอบสถานะทันทีที่โหลดหน้าเว็บเสร็จ
setTimeout(dpb_updateSolidwoodVisibility, 100);


const activeToasts = new Map();

function getToastContainer() {
    let container = document.getElementById('dpb-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'dpb-toast-container';
        document.body.appendChild(container);
    }
    return container;
}

function getFieldKey(el) {
    if (el.name && el.name.trim() !== '') {
        return 'name:' + el.name.trim();
    }
    if (el.id) {
        return 'id:' + el.id;
    }
    el.id = 'dpb-gen-' + Math.random().toString(36).substr(2, 9);
    return 'id:' + el.id;
}

function showToast(message, sourceElement) {
    if (window.innerWidth < 768) return;
    if (!message || !sourceElement) return;

    const container = getToastContainer();
    const uniqueKey = getFieldKey(sourceElement); // ได้ Key เช่น "name:offsetY"

    let existingToast = container.querySelector(`.dpb-toast[data-toast-key="${uniqueKey}"]`);

    let record = activeToasts.get(uniqueKey);

    if (existingToast && !record) {
        record = { toast: existingToast, timer: null };
        activeToasts.set(uniqueKey, record);
    }

    if (record || existingToast) {
        const targetToast = record ? record.toast : existingToast;

        if (record && record.timer) {
            clearTimeout(record.timer);
            record.timer = null;
        }

        const textSpan = targetToast.querySelector('.dpb-toast-msg');
        if (textSpan && textSpan.textContent !== message) {
            textSpan.textContent = message;
        }

        if (!targetToast.classList.contains('show')) {
            requestAnimationFrame(() => targetToast.classList.add('show'));
        }

        if (!record) {
             activeToasts.set(uniqueKey, { toast: targetToast, timer: null });
        }
        
        return; 
    }

    const toast = document.createElement('div');
    toast.className = 'dpb-toast';
    toast.setAttribute('data-toast-key', uniqueKey);
    
    toast.innerHTML = `
        <span class="dpb-toast-icon">⚠️</span>
        <span class="dpb-toast-msg">${message}</span>
    `;
    
    container.appendChild(toast);

    activeToasts.set(uniqueKey, { toast: toast, timer: null });

    requestAnimationFrame(() => {
        toast.classList.add('show');
    });
}

function hideToast(sourceElement) {
    if (!sourceElement) return;
    
    const uniqueKey = getFieldKey(sourceElement);

    let record = activeToasts.get(uniqueKey);

    if (!record) {
         const container = document.getElementById('dpb-toast-container');
         const zombie = container ? container.querySelector(`.dpb-toast[data-toast-key="${uniqueKey}"]`) : null;
         if (zombie) {
             record = { toast: zombie, timer: null };
             activeToasts.set(uniqueKey, record);
         }
    }

    if (!record) return;

    if (record.timer) clearTimeout(record.timer);
    
    record.timer = setTimeout(() => {
        const toast = record.toast;
        toast.classList.remove('show');
        
        setTimeout(() => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 350);
        
        activeToasts.delete(uniqueKey);
    }, 100);
}

function setFieldError(el, msg, _mirrorDone, popupMsg){
  if (!el) return;

  el.classList.toggle('dpb-invalid', !!msg);
  
  var note = el.parentElement.querySelector('.dpb-field-note');
  if(!note){
    note = document.createElement('div');
    note.className = 'dpb-field-note';
    el.parentElement.appendChild(note);
  }
  note.textContent = msg || '';
  note.style.display = msg ? '' : 'none';

  if (msg) {
      const uniqueKey = getFieldKey(el);

      const isAlreadyShown = !!document.querySelector(`.dpb-toast[data-toast-key="${uniqueKey}"]`);

      const isUserFocused = (document.activeElement === el);
      const isTypeChanging = (document.activeElement && document.activeElement.id === 'dpb-type');

      if (!isTypeChanging && (isAlreadyShown || isUserFocused)) {
          const alertText = popupMsg || msg;
          showToast(alertText, el);
      } else {
          hideToast(el); 
      }
  } else {
      hideToast(el);
  }

  
  try{
    if (_mirrorDone) return; 
    var typeVal = (document.getElementById('dpb-type')?.value || '').trim().toLowerCase();
    if (typeVal === 'l2' || typeVal === 'l3') return;

    var isGapField = !!(el.id && (/^dpb-gap/i.test(el.id) || /gap(left|right)$/i.test(el.id)));
    if (!isGapField) return;

    var txt = String(msg || '');
    var isPerFieldLimit = /\(ห้ามเกิน\s*\d+\s*cm\)/i.test(txt);
    if (isPerFieldLimit) return; 

    var isMin5Msg = /5\s*cm|5\s*ซม|5cm|แนะนำให้ขอบโต๊ะมีระยะห่าง\s*5\s*cm\s*ขึ้นไป/i.test(txt);
    if (isMin5Msg) return; 

    var isGenericOverlap = /ลดระยะห่างลง|ซ้อน/i.test(txt);
    if (!isGenericOverlap) return;

    var peers = [];
    var row = el.closest('.dpb-row-2');
    if (row){
      var inputs = row.querySelectorAll('input[id^="dpb-gap"], input[id$="gap-left"], input[id$="gap-right"], input[id$="gapL"], input[id$="gapR"]');
      inputs.forEach(function(p){
        if (p !== el && p.offsetParent !== null) peers.push(p);
      });
    }else{
      ['dpb-gap-left','dpb-gap-right','dpb-gapL','dpb-gapR','dpb-gapA','dpb-gapB','dpb-gap-leftL','dpb-gap-rightL'].forEach(function(id){
        var p = document.getElementById(id);
        if (p && p !== el && p.offsetParent !== null) peers.push(p);
      });
    }
    for (var j=0; j<peers.length; j++){
      setFieldError(peers[j], txt, true);
    }
  }catch(_){ }
}

function getConstraints(){
  const type = byId('dpb-type').value;
  const rules = [];
  const m = state.meta.models.find(x=>x.model===type);
  if(!m) return rules;
  if(m.min_w && m.max_w){
    rules.push({id:'dpb-mw', min:m.min_w, max:m.max_w, label:'ความกว้าง'});
  }
  if(m.min_l && m.max_l){
    rules.push({id:'dpb-ml', min:m.min_l, max:m.max_l, label:'ความยาว'});
  }
  if(type==='l2' || type==='l3'){
    if(m.min_aw && m.max_aw){
      rules.push({id:'dpb-aw', min:m.min_aw, max:m.max_aw, label:'ความกว้างด้าน L'});
    }
    if(m.min_al && m.max_al){
      rules.push({id:'dpb-al', min:m.min_al, max:m.max_al, label:'ความยาวด้าน L'});
    }
  }
  return rules;
}

function validateInputs() {
  window.state = window.state || {};
  state.validation = state.validation || { ok: true, messages: [] };
  state.validation.ok = true;
  state.validation.messages = [];
  let allValid = true;

  // Clear old errors
  document.querySelectorAll('.dpb-field-note').forEach(n => { n.style.display = 'none'; });
  document.querySelectorAll('.dpb-invalid').forEach(el => el.classList.remove('dpb-invalid'));

  // 1. ตรวจสอบ Constraints พื้นฐาน (Min/Max ของ Model)
  const rules = getConstraints(); 
  rules.forEach(r => {
    const el = byId(r.id);
    if (!el || el.offsetParent === null) return; 
    const raw = (el.value ?? '').toString().trim();
    if (raw === '') {
      const msg = `กรุณากรอก${r.label} (ช่วง ${r.min}–${r.max} ซม.)`;
      setFieldError(el, msg);
      allValid = false;
      state.validation.ok = false;
      state.validation.messages.push({ field: r.id, message: msg });
      return;
    }
    const v = +raw;
    if (Number.isNaN(v) || v < r.min || v > r.max) {
      const msg = `สามารถเลือก${r.label} ได้เพียง ${r.min}–${r.max} ซม. เท่านั้น`;
      setFieldError(el, msg);
      allValid = false;
      state.validation.ok = false;
      state.validation.messages.push({ field: r.id, message: msg });
      return;
    }
    setFieldError(el, '');
  });

  // 2. (เพิ่มใหม่) ตรวจสอบ Constraints ของมุมโค้ง (Radius)
  const isRadiusValid = validateRadiusConstraints();
  if (!isRadiusValid) {
    allValid = false;
    state.validation.ok = false;
    // หมายเหตุ: ข้อความ error ถูกจัดการใน validateRadiusConstraints แล้ว
  }

  const btnPrev = byId('dpb-preview');
  const btnDown = byId('dpb-download');
  if (btnPrev) btnPrev.disabled = !allValid;
  if (btnDown) btnDown.disabled = !allValid;

  try {
    document.dispatchEvent(new CustomEvent('dpb:validation-changed', {
      detail: { ok: allValid, messages: state.validation.messages.slice() }
    }));
  } catch (_) { }

  return allValid;
}

    function applyTypeDefaults(){
      const t = byId('dpb-type').value;
      if(t==='custom' || t==='single'){
        if(!byId('dpb-mw').value) byId('dpb-mw').value = (t==='single')? '60':'60';
        if(!byId('dpb-ml').value) byId('dpb-ml').value = (t==='single')? '80':'200';
      }else{
        if(!byId('dpb-aw').value) byId('dpb-aw').value=120;
        if(!byId('dpb-al').value) byId('dpb-al').value=60;
      }
      syncRBlocks();
    }

    function genAutoFilename(){
      const type = byId('dpb-type').selectedOptions[0]?.text || 'Desk';
      const name = (byId('dpb-customer').value||'Customer').replace(/\s+/g,'');
      const d = byId('dpb-date').value; let pretty='';
      if(d){ const [yy,mm,dd] = d.split('-'); pretty = `${dd}${mm}${yy}`; }
      else { const t=new Date(); const dd=String(t.getDate()).padStart(2,'0'); const mm=String(t.getMonth()+1).padStart(2,'0'); const yy=t.getFullYear(); pretty=`${dd}${mm}${yy}`; }
      return `${type}_${name}_${pretty}`.replace(/[^\w\-\_\.]+/g,'_'); }

function syncRBlocks(initial = false) {
  const edgeVal = document.getElementById('dpb-edge')?.value || 'square';
  const typeVal = document.getElementById('dpb-type')?.value || 'custom';
  const isRounded = (edgeVal === 'rounded');
  const rRect = document.getElementById('dpb-r-rect');
  const rLdesk = document.getElementById('dpb-r-ldesk');
  
  if (rRect) rRect.style.display = (isRounded && (typeVal === 'custom' || typeVal === 'single' || typeVal === 'custom_workspace' || typeVal === 'custom_single' || typeVal === 'custom_manual')) ? '' : 'none';
  if (rLdesk) rLdesk.style.display = (isRounded && (typeVal === 'l2' || typeVal === 'l3')) ? '' : 'none';
  const setDefaultIfEmpty = (id, defVal) => {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.value === '' || el.value === null || Number(el.value) === 0) {
      el.value = String(defVal);
    }
  };

  if (isRounded) {
    if (typeVal === 'l2' || typeVal === 'l3') {
      ['ld_r_tl', 'ld_r_tr', 'ld_r_step', 'ld_r_br', 'ld_r_armbl', 'ld_r_armbr'].forEach(id => {
        setDefaultIfEmpty(id, 50); 
      });
      setDefaultIfEmpty('dpb-rInner', 150);
    } else {
      ['r_rect_tl', 'r_rect_tr', 'r_rect_bl', 'r_rect_br'].forEach(id => {
        setDefaultIfEmpty(id, 50);
      });
    }
  } else {
    const allIds = [
      'r_rect_tl', 'r_rect_tr', 'r_rect_bl', 'r_rect_br',
      'ld_r_tl', 'ld_r_tr', 'ld_r_step', 'ld_r_br', 'ld_r_armbl', 'ld_r_armbr',
      'dpb-rInner'
    ];
    allIds.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '0';
    });
  }

  if (!initial && typeof scheduleRedraw === "function") {
    scheduleRedraw();
  }
}

function validateRadiusConstraints() {
    const type = document.getElementById('dpb-type')?.value || 'custom';
    const edgeVal = document.getElementById('dpb-edge')?.value;
    
    // ถ้าไม่ได้เลือกแบบมุมมน ไม่ต้องตรวจสอบ
    if (edgeVal !== 'rounded') return true;

    let allOk = true;

    // Helper: ดึงค่า cm แปลงเป็น mm
    const getDimMM = (id) => {
        const el = document.getElementById(id);
        return el ? (Number(el.value) || 0) * 10 : 0;
    };
    // Helper: ดึงค่ามุม (mm อยู่แล้ว)
    const getRadMM = (id) => {
        const el = document.getElementById(id);
        return el ? (Number(el.value) || 0) : 0;
    };

    // Helper: ฟังก์ชันตรวจสอบมุม
    const checkCorner = (fieldId, name, checks) => {
        const el = document.getElementById(fieldId);
        if (!el || el.offsetParent === null) return; 

        const currentVal = Number(el.value) || 0;
        let maxAllowed = 99999; 
        let limitingFactor = '';

        checks.forEach(c => {
            const neighborVal = getRadMM(c.neighbor);
            const limit = c.limit;
            const spaceAvailable = limit - neighborVal;
            
            if (spaceAvailable < maxAllowed) {
                maxAllowed = spaceAvailable;
                limitingFactor = c.limitName || '';
            }
        });

        if (maxAllowed < 0) maxAllowed = 0;

        if (currentVal > maxAllowed) {
            allOk = false;
            const shortMsg = `เลือกขนาดมุมได้ไม่เกิน ${maxAllowed} mm`;
            // popupMsg อาจจะระบุด้วยว่าติดที่ค่าไหน (Optional)
            const popupMsg = `สามารถเลือกขนาด${name}ได้ไม่เกิน ${maxAllowed} mm (ติดข้อจำกัดด้าน${limitingFactor})`;
            
            setFieldError(el, shortMsg, false, popupMsg);
            
            if(window.state && window.state.validation){
                 state.validation.messages.push({ field: fieldId, message: popupMsg });
            }
        } else {
            const currentErr = el.parentElement.querySelector('.dpb-field-note')?.textContent || '';
            if (currentErr.includes('เลือกขนาดมุมได้ไม่เกิน')) {
                setFieldError(el, '');
            }
        }
    };

    if (type === 'l2' || type === 'l3') {
        // === โต๊ะตัว L ===
        const side = document.getElementById('dpb-aside')?.value || 'left';
        
        // ขนาดต่างๆ
        const W = getDimMM('dpb-mw');   // ความลึกแผ่นหลัก (Main Depth)
        const L = getDimMM('dpb-ml');   // ความยาวรวม (Total Length)
        const AW = getDimMM('dpb-aw');  // ความลึกรวมฝั่งแขน (Total Depth L-Side)
        const AL = getDimMM('dpb-al');  // ความกว้างแขน (Arm Width)

        if (side === 'left') {
            // === กรณี L หันซ้าย (แขนอยู่ซ้าย) ===
            // โครงสร้าง: ซ้ายลึก (AW), ขวาสั้น (W)
            
            // 1. มุมบนซ้าย (TL): เช็คแนวนอน (L) และแนวตั้งลึก (AW)
            checkCorner('ld_r_tl', 'มุมบนซ้าย', [
                { neighbor: 'ld_r_tr', limit: L, limitName: 'ความยาวโต๊ะ' },     
                { neighbor: 'ld_r_armbl', limit: AW, limitName: 'ความลึกแขน L' } 
            ]);

            // 2. มุมบนขวา (TR): เช็คแนวนอน (L) และแนวตั้งสั้น (W)
            checkCorner('ld_r_tr', 'มุมบนขวา', [
                { neighbor: 'ld_r_tl', limit: L, limitName: 'ความยาวโต๊ะ' },     
                { neighbor: 'ld_r_br', limit: W, limitName: 'ความลึกแผ่นหลัก' }      
            ]);

            // 3. มุมล่างขวา (BR): เช็คแนวตั้งสั้น (W)
            checkCorner('ld_r_br', 'มุมล่างขวา', [
                { neighbor: 'ld_r_tr', limit: W, limitName: 'ความลึกแผ่นหลัก' }
            ]);

            // 4. มุมปลายแขนซ้าย (ArmBL): เช็คแนวตั้งลึก (AW) และ ความกว้างแขน (AL)
            // *จุดนี้สำคัญ* ต้องเช็คกับ ArmBR (มุมใน) ด้วย เพื่อไม่ให้เกินความกว้างแขน
            checkCorner('ld_r_armbl', 'มุมปลายแขนซ้าย', [
                { neighbor: 'ld_r_tl', limit: AW, limitName: 'ความลึกแขน L' },
                { neighbor: 'ld_r_armbr', limit: AL, limitName: 'ความกว้างแขน L' } 
            ]);

            // 5. มุมในปลายแขน (ArmBR): เช็คความกว้างแขน (AL)
            checkCorner('ld_r_armbr', 'มุมปลายแขนขวา(ใน)', [
                { neighbor: 'ld_r_armbl', limit: AL, limitName: 'ความกว้างแขน L' }
            ]);

        } else {
            // === กรณี L หันขวา (แขนอยู่ขวา) ===
            // โครงสร้าง: ซ้ายสั้น (W), ขวาลึก (AW)

            // 1. มุมบนขวา (TR): เช็คแนวนอน (L) และแนวตั้งลึก (AW)
            checkCorner('ld_r_tr', 'มุมบนขวา', [
                { neighbor: 'ld_r_tl', limit: L, limitName: 'ความยาวโต๊ะ' },
                { neighbor: 'ld_r_armbr', limit: AW, limitName: 'ความลึกแขน L' }
            ]);

            // 2. มุมบนซ้าย (TL): เช็คแนวนอน (L) และแนวตั้งสั้น (W)
            checkCorner('ld_r_tl', 'มุมบนซ้าย', [
                { neighbor: 'ld_r_tr', limit: L, limitName: 'ความยาวโต๊ะ' },
                { neighbor: 'ld_r_step', limit: W, limitName: 'ความลึกแผ่นหลัก' } // ld_r_step คือมุมล่างซ้ายของแผ่นหลัก
            ]);

            // 3. มุมล่างซ้ายแผ่นหลัก (Step/BL): เช็คแนวตั้งสั้น (W)
            checkCorner('ld_r_step', 'มุมล่างซ้าย', [
                { neighbor: 'ld_r_tl', limit: W, limitName: 'ความลึกแผ่นหลัก' }
            ]);

            // 4. มุมปลายแขนขวา (ArmBR): เช็คแนวตั้งลึก (AW) และ ความกว้างแขน (AL)
            checkCorner('ld_r_armbr', 'มุมปลายแขนขวา', [
                { neighbor: 'ld_r_tr', limit: AW, limitName: 'ความลึกแขน L' },
                { neighbor: 'ld_r_armbl', limit: AL, limitName: 'ความกว้างแขน L' }
            ]);

            // 5. มุมในปลายแขน (ArmBL): เช็คความกว้างแขน (AL)
            checkCorner('ld_r_armbl', 'มุมปลายแขนซ้าย(ใน)', [
                { neighbor: 'ld_r_armbr', limit: AL, limitName: 'ความกว้างแขน L' }
            ]);
        }

    } else {
        // === โต๊ะสี่เหลี่ยมปกติ ===
        const W = getDimMM('dpb-mw');
        const L = getDimMM('dpb-ml');

        checkCorner('r_rect_tl', 'มุมบนซ้าย', [
            { neighbor: 'r_rect_tr', limit: L, limitName: 'ความยาว' },
            { neighbor: 'r_rect_bl', limit: W, limitName: 'ความลึก' }
        ]);
        checkCorner('r_rect_tr', 'มุมบนขวา', [
            { neighbor: 'r_rect_tl', limit: L, limitName: 'ความยาว' },
            { neighbor: 'r_rect_br', limit: W, limitName: 'ความลึก' }
        ]);
        checkCorner('r_rect_br', 'มุมล่างขวา', [
            { neighbor: 'r_rect_bl', limit: L, limitName: 'ความยาว' },
            { neighbor: 'r_rect_tr', limit: W, limitName: 'ความลึก' }
        ]);
        checkCorner('r_rect_bl', 'มุมล่างซ้าย', [
            { neighbor: 'r_rect_br', limit: L, limitName: 'ความยาว' },
            { neighbor: 'r_rect_tl', limit: W, limitName: 'ความลึก' }
        ]);
    }

    return allOk;
}

function normalizeOptConfigsOnTypeChange(){
  Object.keys(state.optConfig || {}).forEach(key=>{
    const arr = state.optConfig[key] || [];
    arr.forEach(cfg=>{
      if(!cfg || typeof cfg !== 'object') return;
      if(!cfg.pos) cfg.pos = 'main';
      if(!Number.isFinite(cfg.offsetY)) cfg.offsetY = 5; 
      const place = String(cfg.place||'').toLowerCase();
      if(place === 'center'){
        cfg.offsetX = 0; 
      }else{
        if(!Number.isFinite(cfg.offsetX)) cfg.offsetX = 10; 
      }
    });
  });
}

byId('dpb-edge').addEventListener('change', () => syncRBlocks());

byId('dpb-type').addEventListener('change', ()=>{
  toggleLDeskExtra?.();
  applyTypeDefaults?.();
  syncRBlocks?.();
  normalizeOptConfigsOnTypeChange();
  buildOptConfig?.();
  updateCartBadge?.();
  validateInputs?.();
  scheduleRedraw?.();
});

byId('dpb-aside')?.addEventListener('change', ()=>{
  toggleAside?.();        
  buildOptConfig?.();     
  validateInputs?.();
  scheduleRedraw?.();
});

syncRBlocks(true);

function totalOptions(){
  return totalSelectedCount();
}

function isDesktop(){
  return window.matchMedia('(min-width:901px)').matches;
}

function setMainWrapInert(active){
  if(!mainWrap) return;
  const desktop = isDesktop();
  if(desktop){
    mainWrap.removeAttribute('aria-hidden');
    mainWrap.removeAttribute('data-cart-inert');
    if(supportsInert){ mainWrap.inert = false; }
    return;
  }
  if(active){
    mainWrap.setAttribute('aria-hidden','true');
    mainWrap.setAttribute('data-cart-inert','true');
    if(supportsInert){ mainWrap.inert = true; }
  }else{
    mainWrap.removeAttribute('aria-hidden');
    mainWrap.removeAttribute('data-cart-inert');
    if(supportsInert){ mainWrap.inert = false; }
  }
}

function focusCartHeader(){
  const desktop = isDesktop();
  if(!desktop && cartCloseMobile){ cartCloseMobile.focus(); return; }
  if(desktop && cartCloseDesktop){ cartCloseDesktop.focus(); }
}

function openCart(){
  const desktop = isDesktop();
  if (totalOptions() === 0){
    cartEmpty.style.display='block';
    cartBody.style.display='none';
  }
  cartPanel.classList.add('is-open');
  if (desktop){
    requestAnimationFrame(setMobileCartHeightToCanvasBottom);
    document.body.classList.add('dpb-cart-open-desktop');
    state.desktopCartOpen = true;
  }else{
    cartBackdrop.classList.remove('is-active');
    document.body.classList.add('dpb-cart-lock'); 
    setMainWrapInert(true);
    requestAnimationFrame(() => requestAnimationFrame(requestMobileCartHeightUpdate));
  }
  cartButton.setAttribute('aria-expanded','true');
  cartPanel.setAttribute('aria-hidden','false');
  focusCartHeader();
}
window.openCart = openCart;

function closeCart(skipHistory=false){
  if(!cartPanel.classList.contains('is-open')) return;
  cartPanel.classList.remove('is-open');      
  cartBackdrop.classList.remove('is-active');
  document.body.classList.remove('dpb-cart-lock');
  setMainWrapInert(false);
  if(isDesktop && isDesktop()){
    document.body.classList.remove('dpb-cart-open-desktop');
    state.desktopCartOpen = false;
  }
  cartButton.setAttribute('aria-expanded','false');
  cartPanel.setAttribute('aria-hidden','true');
  if(cartButton) cartButton.focus();
  if (confirmDialog.classList.contains('is-open')) hideConfirm();
  if (!skipHistory && cartHistoryToken !== null){
    if (supportsHistory){
      suppressPopstate = true;
      cartHistoryToken = null;
      history.back();
      setTimeout(()=>{ suppressPopstate = false; }, 0);
    } else {
      cartHistoryToken = null;
    }
  }
  if (skipHistory){ cartHistoryToken = null; }
}

(function(){
  const root = document.documentElement;
  const panelInner = document.querySelector('.dpb-cart-panel__inner');
  function setVH(){
    const vh = (window.visualViewport ? visualViewport.height : window.innerHeight) * 0.01;
    root.style.setProperty('--vh', `${vh}px`);
  }
  function setCartHeight(pct = 50){
    root.style.setProperty('--dpb-cart-h', `calc(var(--vh) * ${pct})`);
  }
  setVH(); setCartHeight(50);
  window.addEventListener('resize', setVH);
  if (window.visualViewport){
    visualViewport.addEventListener('resize', setVH);
    visualViewport.addEventListener('scroll', setVH);
  }
  const cartPanel = document.getElementById('dpb-cart-panel') || document.querySelector('.dpb-cart-panel');
  const cartBtn   = document.getElementById('dpb-cart-button');
  const closeMb   = document.getElementById('dpb-cart-close-mobile');
  const closeDt   = document.getElementById('dpb-cart-close-desktop');
  function refreshCartViewport(){
    const isSmall = Math.min(window.innerWidth, window.innerHeight) < 640;
    setVH();
    setCartHeight(isSmall ? 60 : 50); 
    if (panelInner){
      panelInner.style.willChange = 'transform';
      requestAnimationFrame(()=>{ panelInner.style.willChange = 'auto'; });
    }
  }
  const safe = fn => fn && typeof fn === 'function';
  const _openCart  = window.openCart;
  const _closeCart = window.closeCart;
  window.openCart = function(){
    safe(_openCart) && _openCart.apply(this, arguments);
    refreshCartViewport();
  };
  window.closeCart = function(){
    safe(_closeCart) && _closeCart.apply(this, arguments);
  };
  if (cartBtn)  cartBtn.addEventListener('click', refreshCartViewport, {passive:true});
  if (closeMb)  closeMb.addEventListener('click', refreshCartViewport, {passive:true});
  if (closeDt)  closeDt.addEventListener('click', refreshCartViewport, {passive:true});
})();

function setCartEmptyState(isEmpty){
  const panel = document.querySelector('.dpb-cart-panel'); 
  if(panel){
    panel.classList.toggle('is-empty',  !!isEmpty);
    panel.classList.toggle('has-items', !isEmpty);
  }
  if (window.cartEmpty) cartEmpty.style.display = isEmpty ? '' : 'none';
  if (window.cartBody)  cartBody.style.display  = isEmpty ? 'none' : 'flex';
}

function showConfirm(){
  confirmDialog.classList.add('is-open');
  confirmDialog.setAttribute('aria-hidden','false');
}

function hideConfirm(){
  confirmDialog.classList.remove('is-open');
  confirmDialog.setAttribute('aria-hidden','true');
}

function clearAllOptions(){
  Object.keys(state.selectedOptions).forEach(key=>{
    state.selectedOptions[key] = {count:0};
    state.optConfig[key] = [];
    state.uiExpanded[key] = {};
    updateOptCardCount(key);
  });
  buildOptConfig();
  updateCartBadge();
  closeCart();
  scheduleRedraw();
}

if (cartButton) {
  cartButton.addEventListener('click', () => {
    try { scrollToTopSmooth(); } catch (e) {}
    if (cartPanel.classList.contains('is-open')) {
      closeCart();
    } else {
      buildOptConfig();
      openCart();
    }
  });
}


cartBackdrop.addEventListener('click', () => {
    if (!isDesktop()) closeCart(); 
});


cartCloseMobile.addEventListener('click', ()=>closeCart());
cartCloseDesktop.addEventListener('click', ()=>closeCart());
cartConfirm.addEventListener('click', ()=>closeCart());

cartClear.addEventListener('click', ()=>{
  if(totalOptions() === 0){
    closeCart();
    return;
  }
  showConfirm();
});
confirmNo.addEventListener('click', hideConfirm);
confirmYes.addEventListener('click', ()=>{
  hideConfirm();
  clearAllOptions();
  buildOptions();
});

document.addEventListener('keydown', e=>{
  if(e.key === 'Escape'){
    if(confirmDialog.classList.contains('is-open')) hideConfirm();
    else if(cartPanel.classList.contains('is-open')) closeCart();
  }
});

if(supportsHistory){
  window.addEventListener('popstate', event=>{
    if(suppressPopstate){
      suppressPopstate = false;
      return;
    }
    if(cartHistoryToken !== null){
      if(confirmDialog.classList.contains('is-open')) hideConfirm();
      closeCart(true);
    }
  });
}

function enableDragClose(){
  return;
}

const themeBtn      = document.getElementById('dpb-theme-btn');
const themePanel    = document.getElementById('dpb-theme-panel');
const themeBackdrop = document.getElementById('dpb-theme-backdrop');
const themeClose    = document.getElementById('dpb-theme-close');
const themeConfirm  = document.getElementById('dpb-theme-confirm');

function openTheme(){
  themePanel.classList.add('is-open');
  themeBackdrop.classList.add('is-active');
}

function closeTheme(){
  themePanel.classList.remove('is-open');
  themeBackdrop.classList.remove('is-active');
}

if(themeBtn) themeBtn.addEventListener('click', openTheme);
if(themeClose) themeClose.addEventListener('click', closeTheme);
if(themeBackdrop) themeBackdrop.addEventListener('click', closeTheme);

(function(){
  window.state = window.state || {};
  state.ui = state.ui || {};
  if (typeof state.ui.showInfo === 'undefined') {
    state.ui.showInfo = true;
  }
  window.dpbShouldShowInfo = function(){
    return state?.ui?.showInfo !== false;
  };
  window.dpbSetShowInfo = function(on){
    state.ui = state.ui || {};
    state.ui.showInfo = !!on;
    if (typeof scheduleRedraw === 'function') scheduleRedraw();
  };
  function syncInfoToggleToUI(){
    const el = document.getElementById('dpb-info-toggle');
    if (!el) return;
    el.checked = !!dpbShouldShowInfo();
  }
  function initInfoToggle(){
    const el = document.getElementById('dpb-info-toggle');
    if (!el) return;
    el.addEventListener('change', (e)=>{
      dpbSetShowInfo(e.target.checked);
    });
    syncInfoToggleToUI();
  }
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initInfoToggle, { once:true });
  }else{
    initInfoToggle();
  }
  if (typeof openTheme === 'function'){
    const _origOpenTheme = openTheme;
    window.openTheme = function(){
      _origOpenTheme();
      setTimeout(()=>{ try{ syncInfoToggleToUI(); }catch(_){} }, 0);
    };
  }
})();

function initColorGroup(id, defaultValue, onPick){
  const group = document.getElementById(id);
  if(!group) return;
  const buttons = group.querySelectorAll('button');
  if(defaultValue){
    buttons.forEach(btn=>{
      if(btn.dataset.value === defaultValue){
        btn.classList.add('active');
        group.dataset.selected = defaultValue;
      }
    });
  }
  buttons.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      buttons.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      group.dataset.selected = btn.dataset.value;
      if(typeof onPick === 'function') onPick(btn.dataset.value);
    });
  });
}

state.theme = state.theme || {};
if (typeof state.theme.userPickedIn === 'undefined')  state.theme.userPickedIn  = false;
if (typeof state.theme.userPickedOut === 'undefined') state.theme.userPickedOut = false;

initColorGroup('dpb-bg', '#ffffff');

initColorGroup('dpb-color-in', '#000000', (val)=>{
  state.theme.userPickedIn = true;      
  state.theme.colorIn = val;            
  if(typeof scheduleRedraw==='function') scheduleRedraw();
});

initColorGroup('dpb-color-out', '#000000', (val)=>{
  state.theme.userPickedOut = true;
  state.theme.colorOut = val;
  if(typeof scheduleRedraw==='function') scheduleRedraw();
});

if(themeConfirm){
  themeConfirm.addEventListener('click', ()=>{
    const bg    = document.getElementById('dpb-bg').dataset.selected;
    const colIn = document.getElementById('dpb-color-in').dataset.selected;
    const colOut= document.getElementById('dpb-color-out').dataset.selected;
    state.theme = state.theme || {};
    state.theme.bg = bg;
    if (state.theme.userPickedIn) {
      state.theme.colorIn = colIn;
    }
    state.theme.colorOut = colOut;
    if(typeof scheduleRedraw==='function'){ scheduleRedraw(); }
    closeTheme();
  });
}

function handleResize(){
  if(!cartPanel.classList.contains('is-open')) return;
  if(!isDesktop()){
    requestMobileCartHeightUpdate();
    document.body.classList.remove('dpb-cart-open-desktop');
    state.desktopCartOpen = false;
    cartBackdrop.classList.add('is-active');      
    document.body.classList.add('dpb-cart-lock'); 
    setMainWrapInert(true);                        
  }else{
    document.body.classList.add('dpb-cart-open-desktop');
    state.desktopCartOpen = true;
    cartBackdrop.classList.remove('is-active');
    document.body.classList.remove('dpb-cart-lock');
    setMainWrapInert(false);
  }
  cartPanel.classList.add('is-open');
}
window.addEventListener('resize', handleResize);

function _vh() {
  return (window.visualViewport && window.visualViewport.height) || window.innerHeight;
}

function _getCanvasRef() {
  const byClass = document.querySelector('.dpb-card-canvas');
  if (byClass) return byClass;
  const canvas = document.getElementById('dpb-canvas');
  if (canvas) {
    const wrap = canvas.closest('.dpb-card-canvas');
    return wrap || canvas;
  }
  return null;
}

function setMobileCartHeightToCanvasBottom() {
  if (isDesktop && typeof isDesktop === 'function' && isDesktop()) return;
  const cartRoot = document.getElementById('dpb-cart-panel');
  if (!cartRoot || !cartRoot.classList.contains('is-open')) return;
  const panel = document.querySelector('.dpb-cart-panel__inner');
  if (!panel) return;
  const refEl = (function(){
    const wrap = document.querySelector('.dpb-card-canvas');
    if (wrap) return wrap;
    const c = document.getElementById('dpb-canvas');
    return c ? (c.closest('.dpb-card-canvas') || c) : null;
  })();
  const vh = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
  const GAP = 8, MIN = 260, MAX = Math.round(vh * 0.92);
  let h = Math.round(vh * 0.6);
  if (refEl){
    const r = refEl.getBoundingClientRect();
    if (r.bottom <= vh){
      h = Math.max(MIN, Math.min(vh - r.bottom - GAP, MAX));
    }
  }
  document.documentElement.style.setProperty('--cart-h', `${h}px`);
}

let _cartHeightRaf = 0;
function requestMobileCartHeightUpdate() {
  if (_cartHeightRaf) return;
  _cartHeightRaf = requestAnimationFrame(() => {
    _cartHeightRaf = 0;
    setMobileCartHeightToCanvasBottom();
  });
}

function setMobileThemeHeightToCanvasBottom() {
  if (isDesktop && typeof isDesktop === 'function' && isDesktop()) return;
  const panel = document.querySelector('#dpb-theme-panel .dpb-theme-panel__inner');
  if (!panel) return;
  const refEl = (function _getCanvasRef() {
    const byClass = document.querySelector('.dpb-card-canvas');
    if (byClass) return byClass;
    const canvas = document.getElementById('dpb-canvas');
    return canvas ? (canvas.closest('.dpb-card-canvas') || canvas) : null;
  })();
  const vh = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
  const GAP = 8;
  const MIN = 260;
  const MAX = Math.round(vh * 0.92);
  let heightPx = Math.round(vh * 0.6); 
  if (refEl) {
    const rect = refEl.getBoundingClientRect();
    if (rect.bottom <= vh) {
      const avail = Math.max(0, vh - rect.bottom - GAP);
      heightPx = Math.max(MIN, Math.min(avail, MAX));
    }
  }
  document.documentElement.style.setProperty('--cart-h', `${heightPx}px`);
}

let _themeHeightRaf = 0;
function requestMobileThemeHeightUpdate() {
  if (_themeHeightRaf) return;
  _themeHeightRaf = requestAnimationFrame(() => {
    _themeHeightRaf = 0;
    setMobileThemeHeightToCanvasBottom();
  });
}

(function(){
  const ScrollLock = (()=>{
  const isIOS = /iP(ad|hone|od)/.test(navigator.userAgent);
  let locked = false;
  let y = 0;
  let unlockTimer = null;
  const getSBW = ()=> window.innerWidth - document.documentElement.clientWidth;
  function lock(){
    if (locked) return;
    locked = true;
    y = window.scrollY || document.documentElement.scrollTop || 0;
    const sbw = getSBW();
    if (sbw > 0) {
      document.documentElement.style.paddingRight = sbw + 'px';
      document.body.style.paddingRight = sbw + 'px';
    }
    document.documentElement.classList.add('no-scroll');
    document.body.classList.add('no-scroll');
    if (isIOS){
      document.body.style.position = 'fixed';
      document.body.style.top = `-${y}px`;
      document.body.style.left = '0';
      document.body.style.right = '0';
      document.body.style.width = '100%';
    }
  }
  function _doUnlock(){
    if (!locked) return;
    locked = false;
    document.documentElement.classList.remove('no-scroll');
    document.body.classList.remove('no-scroll');
    document.documentElement.style.paddingRight = '';
    document.body.style.paddingRight = '';
    if (isIOS){
      const py = parseInt(document.body.style.top || '0', 10) || 0;
      document.body.style.position = '';
      document.body.style.top = '';
      document.body.style.left = '';
      document.body.style.right = '';
      document.body.style.width = '';
      window.scrollTo(0, -py);
    }
  }
  function unlock(){
    if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null; }
    _doUnlock();
  }
  function unlockDebounced(ms = 600){
    if (unlockTimer) clearTimeout(unlockTimer);
    unlockTimer = setTimeout(()=> { unlockTimer = null; _doUnlock(); }, ms);
  }
  return { lock, unlock, unlockDebounced, get locked(){ return locked; } };
})();

  function anyModalOpen(){
    const dpbOpen = !!document.querySelector('.dpb-modal.is-open, .dpb-modal[aria-hidden="false"]');
    const emOpen  = !!document.querySelector('.elementor-popup-modal.elementor-lightbox-open');
    return dpbOpen || emOpen;
  }

function installModalObserver(){
  const targets = [
    document.body,
    ...document.querySelectorAll('.dpb-modal, .elementor-popup-modal')
  ];
  const obs = new MutationObserver(()=> {
    if (anyModalOpen()) ScrollLock.lock();
    else ScrollLock.unlockDebounced(650);  
  });
  targets.forEach(t => obs.observe(t, { attributes: true, childList: true, subtree: true }));
  document.addEventListener('touchmove', (e)=>{
    if (!anyModalOpen()) return;
    const modalContent = e.target.closest('.dpb-modal .dpb-modal__panel, .elementor-popup-modal .dialog-widget-content');
    if (!modalContent) e.preventDefault();
  }, { passive: false });
}

  const _openVariantModalForOption = window.openVariantModalForOption;
  if (typeof _openVariantModalForOption === 'function'){
    window.openVariantModalForOption = function(...args){
      const ret = _openVariantModalForOption.apply(this, args);
      setTimeout(()=> ScrollLock.lock(), 0);
      return ret;
    };
  }
  document.addEventListener('click', (e)=>{
    if (e.target.closest('.dpb-modal__close, .dpb-modal__backdrop')) {
      setTimeout(()=> {
        if (!anyModalOpen()) ScrollLock.unlock();
      }, 0);
    }
  });
  document.addEventListener('elementor/popup/show', ()=> ScrollLock.lock());
  document.addEventListener('elementor/popup/hide', ()=> ScrollLock.unlock());
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installModalObserver);
  } else {
    installModalObserver();
  }
})();

(function bindMobileThemeHeightAutoUpdate(){
  const panelRoot = document.getElementById('dpb-theme-panel');
  if (!panelRoot) return;
  const onToggle = () => {
    const isOpen = panelRoot.classList.contains('is-open');
    if (isOpen && !(isDesktop && isDesktop())){
      requestAnimationFrame(() => requestMobileThemeHeightUpdate());
    }
  };
  new MutationObserver(onToggle)
    .observe(panelRoot, { attributes:true, attributeFilter:['class'] });
  window.addEventListener('resize', requestMobileThemeHeightUpdate, { passive:true });
  if (window.visualViewport){
    window.visualViewport.addEventListener('resize', requestMobileThemeHeightUpdate, { passive:true });
    window.visualViewport.addEventListener('scroll', requestMobileThemeHeightUpdate, { passive:true });
  }
  onToggle();
})();

(function bindMobileCartHeightAutoUpdate(){
  document.addEventListener('scroll', requestMobileCartHeightUpdate, { passive: true, capture: true });
  window.addEventListener('resize', requestMobileCartHeightUpdate, { passive: true });
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', requestMobileCartHeightUpdate, { passive: true });
    window.visualViewport.addEventListener('scroll', requestMobileCartHeightUpdate, { passive: true });
  }
})();

(function initLAsideTiles(){
  var sel  = document.getElementById('dpb-aside');
  var host = document.getElementById('dpb-aside-tiles');
  if(!sel || !host) return;
  var LIST = [
    { value:'left',  label:'ซ้าย', img:'https://www.deskspace.in.th/wp-content/uploads/2025/10/L-Left.png'  }, 
    { value:'right', label:'ขวา', img:'https://www.deskspace.in.th/wp-content/uploads/2025/10/L-Right.png' },
  ];


  renderCards(host, LIST, sel.value, function (v){
    if (sel.value !== v){
      sel.value = v;
      sel.dispatchEvent(new Event('change', { bubbles:true }));
      if (typeof scheduleRedraw==='function') scheduleRedraw();
    }
  });
  sel.addEventListener('change', function(){
    syncCards(host, sel.value);
    if (typeof scheduleRedraw==='function') scheduleRedraw();
  });
  sel.dispatchEvent(new Event('change', { bubbles:false }));
  function renderCards(host, items, cur, onPick){
    host.innerHTML = '';
    items.forEach(function(it, idx){
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'dpb-type-card';
      card.setAttribute('data-value', it.value);
      card.setAttribute('aria-selected', (cur===it.value ? 'true' : 'false'));
      card.innerHTML = '' +
        '<span class="dpb-type-card__chip">' +
          '<img loading="lazy" alt="'+esc(it.label)+'" src="'+it.img+'">' +
        '</span>' +
        '<span class="dpb-type-card__name">'+esc(it.label)+'</span>';
      card.addEventListener('click', function(){
        onPick && onPick(it.value);
        [].slice.call(host.querySelectorAll('.dpb-type-card')).forEach(function(el){
          el.setAttribute('aria-selected','false');
        });
        card.setAttribute('aria-selected','true');
      });
      if (idx===0) card.setAttribute('tabindex','0'); 
      host.appendChild(card);
    });
  }
  function syncCards(host, value){
    [].slice.call(host.querySelectorAll('.dpb-type-card')).forEach(function(el){
      el.setAttribute('aria-selected', el.getAttribute('data-value') === value ? 'true' : 'false');
    });
  }
  function esc(s){ return String(s||'').replace(/[&<>"']/g, function(m){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]);}); }
})();

(function initAsideHintCrossfade(){
  var host     = document.getElementById('dpb-aside-tiles');
  var asideSel = document.getElementById('dpb-aside');
  var rPanel   = document.getElementById('dpb-r-ldesk');
  if (!host || !asideSel || !rPanel) return;
  var BASE = {
    right: 'https://www.deskspace.in.th/wp-content/uploads/2025/10/L-Right.png',
    left:  'https://www.deskspace.in.th/wp-content/uploads/2025/10/L-Left.png'
  };
  var LR = {
    'top-left':      'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-top-left.png',
    'top-right':     'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-top-right.png',
    'bottom-left':   'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-bottom-left.png',
    'bottom-Lleft':  'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-bottom-Lleft.png',
    'bottom-Lright': 'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-bottom-Lright.png',
    'in':            'https://www.deskspace.in.th/wp-content/uploads/2025/10/LR-In.png'
  };
  var LL = {
    'top-left':      'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-top-left.png',
    'top-right':     'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-top-right.png',
    'bottom-right':  'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-bottom-right.png',
    'bottom-Lleft':  'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-bottom-Lleft.png',
    'bottom-Lright': 'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-bottom-Lright.png',
    'in':            'https://www.deskspace.in.th/wp-content/uploads/2025/10/LL-In.png'
  };
  var INPUT_KEY = {
    'ld_r_tl':     'top-left',
    'ld_r_tr':     'top-right',
    'ld_r_step':   'bottom-left',   
    'ld_r_br':     'bottom-right',  
    'ld_r_armbl':  'bottom-Lleft',  
    'ld_r_armbr':  'bottom-Lright', 
    'dpb-rInner':  'in'             
  };
  function getCard(side){ return host.querySelector('.dpb-type-card[data-value="'+ side +'"]'); }
  function getChip(side){ var c=getCard(side); return c ? c.querySelector('.dpb-type-card__chip') : null; }
  function getBaseImg(side){ var chip=getChip(side); return chip ? chip.querySelector('img:not(.dpb-aside-hint)') : null; }
  function getHintImg(side){ var chip=getChip(side); return chip ? chip.querySelector('img.dpb-aside-hint') : null; }
  function ensureHint(side, src){
    var chip = getChip(side); if (!chip) return;
    var hint = getHintImg(side);
    if (!hint){
      hint = document.createElement('img');
      hint.className = 'dpb-aside-hint';
      hint.alt = 'hint';
      hint.decoding = 'async';
      chip.appendChild(hint);
    }
    if (src) hint.src = src;
  }
  function setBase(side){
    var base = getBaseImg(side);
    if (base) base.src = BASE[side];
  }
  function clearAll(){
    ['right','left'].forEach(function(side){
      var card = getCard(side);
      if (card) card.classList.remove('is-hinting');
      var hint = getHintImg(side);
      if (hint) hint.remove(); 
      setBase(side);            
    });
  }
  function currentKey(){
    var a = document.activeElement;
    if (!a || !rPanel.contains(a) || !a.id) return null;
    return INPUT_KEY[a.id] || null;
  }
  function applyState(){
    var side = (asideSel.value || 'right').toLowerCase();
    var key  = currentKey();
    setBase('right'); setBase('left');
    ['right','left'].forEach(function(s){
      var card = getCard(s);
      if (card) card.classList.remove('is-hinting');
      var hint = getHintImg(s);
      if (hint) hint.remove();
    });
    if (!key) return;
    if (side === 'right'){
      ensureHint('right', LR[key] || BASE.right);
      var c = getCard('right'); if (c) c.classList.add('is-hinting');
    } else {
      ensureHint('left', LL[key] || BASE.left);
      var c2 = getCard('left'); if (c2) c2.classList.add('is-hinting');
    }
  }
  rPanel.addEventListener('focusin', function(){ applyState(); }, true);
  rPanel.addEventListener('focusout', function(){
    setTimeout(function(){
      if (!rPanel.contains(document.activeElement)) clearAll();
      else applyState(); 
    }, 0);
  }, true);
  asideSel.addEventListener('change', function(){
    if (rPanel.contains(document.activeElement)) applyState();
    else clearAll();
  });
  clearAll();
})();

(function initEdgeAndLegTiles() {
  const EDGE_ASSETS = {
    standard: [
      { value:'rounded', label:'มุมมน',      img:'https://www.deskspace.in.th/wp-content/uploads/2025/10/RoundEdge.png', trim: null },
      { value:'square',  label:'มุมเหลี่ยม',  img:'https://www.deskspace.in.th/wp-content/uploads/2025/10/SquareEdge.png', trim: null },
    ],
    solid: [
      { value:'rounded', trim:'untrim', label:'มุมมน (ขอบตัดตรง)',      img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/RoundEdgeUnTrim.png' },
      { value:'rounded', trim:'trim',   label:'มุมมน (ขอบลบคม)',       img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/RoundEdgeTrim.png' },
      { value:'square',  trim:'untrim', label:'มุมเหลี่ยม (ขอบตัดตรง)', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/SquareEdgeUnTrim.png' },
      { value:'square',  trim:'trim',   label:'มุมเหลี่ยม (ขอบลบคม)',   img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/SquareEdgeTrim.png' }
    ]
  };

  const edgeSel = document.getElementById('dpb-edge');
  const edgeHost = document.getElementById('dpb-edge-tiles');
  const topSel = document.getElementById('dpb-top-color');
  const trimInput = document.getElementById('dpb-solid-trim');

  function syncEdgeTilesActiveState() {
    if (!edgeHost) return;
    
    const isVirtualSquare = document.body.classList.contains('dpb-virtual-square');
    const targetShape = isVirtualSquare ? 'square' : edgeSel.value;
    const targetTrim = trimInput ? trimInput.value : 'untrim';
    const topKey = topSel ? topSel.value : '';
    const isSolid = (typeof DPB_SOLID_KEYS !== 'undefined') ? DPB_SOLID_KEYS.includes(String(topKey)) : false;
    const cards = edgeHost.querySelectorAll('.dpb-type-card');
    cards.forEach(card => {
      const cardVal = card.getAttribute('data-value');
      const cardTrim = card.getAttribute('data-trim');

      let isActive = false;
      if (isSolid) {
        isActive = (cardVal === targetShape && cardTrim === targetTrim);
      } else {
        isActive = (cardVal === targetShape);
      }
      
      card.setAttribute('aria-selected', isActive ? 'true' : 'false');
      if(isActive) card.classList.add('is-active');
      else card.classList.remove('is-active');
    });
  }

  function updateEdgeTilesForTop() {
    if (!edgeHost || !edgeSel) return;

    const topKey = topSel ? topSel.value : '';
    const isSolid = (typeof DPB_SOLID_KEYS !== 'undefined') ? DPB_SOLID_KEYS.includes(String(topKey)) : false;
    const items = isSolid ? EDGE_ASSETS.solid : EDGE_ASSETS.standard;

    edgeHost.innerHTML = '';
    items.forEach((it, idx) => {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'dpb-type-card';
      card.setAttribute('data-value', it.value);
      if (it.trim) card.setAttribute('data-trim', it.trim);

      card.innerHTML = `
        <span class="dpb-type-card__chip"><img loading="lazy" alt="${escapeHtml(it.label)}" src="${it.img}"></span>
        <span class="dpb-type-card__name">${escapeHtml(it.label)}</span>
      `;
      
      card.addEventListener('click', () => {
        document.body.classList.remove('dpb-virtual-square');

        if (edgeSel.value !== it.value) {
          edgeSel.value = it.value;
          edgeSel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (trimInput && it.trim) {
          trimInput.value = it.trim;
        }
        syncEdgeTilesActiveState();
        if (typeof scheduleRedraw === 'function') scheduleRedraw();
      });
      
      edgeHost.appendChild(card);
    });

    syncEdgeTilesActiveState();
  }
  function checkRadiusInputs() {
    const inputs = [
      'r_rect_tl', 'r_rect_tr', 'r_rect_bl', 'r_rect_br',
      'ld_r_tl', 'ld_r_tr', 'ld_r_step', 'ld_r_br', 'ld_r_armbl', 'ld_r_armbr'
    ];
    
    let allZero = true;
    let hasVisibleInputs = false;

    inputs.forEach(id => {
      const el = document.getElementById(id);
      // เช็คเฉพาะ Input ที่มองเห็นอยู่ (ไม่ถูกซ่อน)
      if (el && el.offsetParent !== null) {
        hasVisibleInputs = true;
        const val = parseFloat(el.value);
        if (!isNaN(val) && val > 0) {
          allZero = false;
        }
      }
    });

    if (!hasVisibleInputs) return;

    const wasVirtual = document.body.classList.contains('dpb-virtual-square');

    if (allZero) {
        if (!wasVirtual) {
            document.body.classList.add('dpb-virtual-square');
            syncEdgeTilesActiveState();
        }
    } else {
        if (wasVirtual) {
            document.body.classList.remove('dpb-virtual-square');
            if (edgeSel.value === 'square') {
                edgeSel.value = 'rounded';
            }
            syncEdgeTilesActiveState();
        }
    }
  }

  document.addEventListener('input', (e) => {
    if (e.target && e.target.id && (e.target.id.startsWith('r_rect_') || e.target.id.startsWith('ld_r_'))) {
        checkRadiusInputs();
    }
  });

  if (edgeSel) {
    edgeSel.addEventListener('change', () => {
      syncEdgeTilesActiveState();
      if(typeof syncRBlocks === 'function') syncRBlocks();
      if(typeof scheduleRedraw === 'function') scheduleRedraw();
    });
  }

  if (topSel) {
    topSel.addEventListener('change', updateEdgeTilesForTop);
  }

  setTimeout(updateEdgeTilesForTop, 100);
  setTimeout(checkRadiusInputs, 200);

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  const legsSel = document.getElementById('dpb-legs');
  const legsHost = document.getElementById('dpb-legs-tiles');
  if (legsSel && !legsSel.options.length) {
     (state.meta.legs||[]).forEach(o=>{ const opt=document.createElement('option'); opt.value=o.key; opt.text=o.name; legsSel.appendChild(opt); });
     if(!legsSel.value && legsSel.options.length) legsSel.value = legsSel.options[0].value;
  }
  if (legsSel && legsHost) {
     const LEGS_LIST = (state.meta.legs||[]).map(item=>({ value: item.key, img: item.imageUrl || '', label: item.name || item.key }));
     
     legsHost.innerHTML = '';
     LEGS_LIST.forEach((it, idx) => {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'dpb-type-card';
        card.setAttribute('data-value', it.value);
        card.innerHTML = `<span class="dpb-type-card__chip"><img loading="lazy" alt="${escapeHtml(it.label)}" src="${it.img}"></span><span class="dpb-type-card__name">${escapeHtml(it.label)}</span>`;
        card.addEventListener('click', () => {
            if (legsSel.value !== it.value){ 
                legsSel.value = it.value; 
                try{drawFooter();}catch(_){} try{measureInfoGrid();}catch(_){} 
                legsSel.dispatchEvent(new Event('change', {bubbles:true})); 
                if(typeof scheduleRedraw==='function') scheduleRedraw(); 
            }
        });
        legsHost.appendChild(card);
     });

     legsSel.addEventListener('change', ()=>{
        [...legsHost.querySelectorAll('.dpb-type-card')].forEach(el=> el.setAttribute('aria-selected', el.dataset.value===legsSel.value?'true':'false'));
        try{drawFooter();}catch(_){} try{measureInfoGrid();}catch(_){} if(typeof scheduleRedraw==='function') scheduleRedraw();
     });
  }

})();

getLegColorFromSelection

window.getLegColorFromSelection = window.getLegColorFromSelection || function(){
  const sel = document.getElementById('dpb-legs');
  const v = (sel && sel.value) ? String(sel.value) : '';
  const legs = Array.isArray(state?.meta?.legs) ? state.meta.legs : [];
  const row  = legs.find(x => String(x.key) === v);
  const haystack = [v, row?.name, row?.imageUrl].filter(Boolean).join(' ').toLowerCase();
  if (/\bblack\b|ดำ/.test(haystack)) return 'black';
  if (/\bwhite\b|ขาว/.test(haystack)) return 'white';
  if (v.toLowerCase().includes('black')) return 'black';
  if (v.toLowerCase().includes('white')) return 'white';
  return 'white';
};

window.getLegShapeFromSelection = window.getLegShapeFromSelection || function(){
  const t = getDeskType();
  if (isLType(t) || isSingleType(t)) return 'square';
  const v = (document.getElementById('dpb-legs')?.value || '').toLowerCase();
  if (/\bcircle\b|กลม/.test(v)) return 'circle';
  return 'square';
};

(function initMasterLegsSystem(){
  const origId   = 'dpb-show-legs';          
  const footerId = 'dpb-show-legs-footer';   
  const getOrig = () => document.getElementById(origId);
  const getFoot = () => document.getElementById(footerId);
  const getType = () => (document.getElementById('dpb-type')?.value || '').toLowerCase();
  const setLegsState = (isShow, skipSyncDOM = false) => {
      window.state = window.state || {};
      window.state.flags = window.state.flags || {};
      window.state.flags.showLegs = isShow;
      const o = getOrig();
      const f = getFoot();
      if (o && o.checked !== isShow) o.checked = isShow;
      if (f && f.checked !== isShow) f.checked = isShow;
      if (typeof scheduleRedraw === 'function') scheduleRedraw();
  };
  const FORCE_HIDE_ON_L3 = false; 
  let _prevLegsState = true;      
  const checkEnforcement = () => {
      if (!FORCE_HIDE_ON_L3) return; 
      const isL3 = (getType() === 'l3');
      const o = getOrig();
      const f = getFoot();
      const wraps = document.querySelectorAll('.dpb-switch-legs');
      if (isL3) {
          if (_prevLegsState === null) _prevLegsState = state.flags.showLegs;
          setLegsState(false);
          wraps.forEach(w => w.style.opacity = '0.5');
          if(o) o.disabled = true;
          if(f) f.disabled = true;
      } else {
          if (_prevLegsState !== null) {
              setLegsState(_prevLegsState);
              _prevLegsState = null;
          }
          wraps.forEach(w => w.style.opacity = '1');
          if(o) o.disabled = false;
          if(f) f.disabled = false;
      }
  };
  const bindToggle = (el) => {
      if (!el) return;
      el.addEventListener('change', (e) => {
          if (!el.disabled) {
              setLegsState(e.target.checked);
              _prevLegsState = e.target.checked; 
          }
      });
  };
  setTimeout(() => {
      const o = getOrig();
      const f = getFoot();
      let initVal = true;
      if (window.state?.flags?.showLegs !== undefined) initVal = window.state.flags.showLegs;
      else if (o) initVal = o.checked;
      setLegsState(initVal); 
      bindToggle(o);
      bindToggle(f);
      const typeSel = document.getElementById('dpb-type');
      if (typeSel) {
          typeSel.addEventListener('change', checkEnforcement);
      }
      checkEnforcement();
  }, 100);
})();

(function initDeskTypeTiles(){
  const sel = document.getElementById('dpb-type');
  const host = document.getElementById('dpb-type-tiles');
  if(!sel || !host) return;
  const TYPES = [
    { value:'custom', label:'Custom Desk<br> (Dual Motor)', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardCustomDeskDuoMotor.png' },
	{ value:'custom_single', label:'Custom Desk<br> (Single Motor)', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardCustomDeskSingleMotor.png' },
    { value:'custom_manual', label:'Custom Desk<br> (Manual)</span>', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardCustomDeskManual.png' },
	{ value:'single', label:'Custom Desk<br> (Single Leg)',  img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardSingleLeg.png' },
    { value:'l2',     label:'Custom L-Desk<br> (2 Legs)', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardDeskL2Leg.png' },
    { value:'l3',     label:'Custom L-Desk<br> (3 Legs)', img:'https://www.deskspace.in.th/wp-content/uploads/2025/12/CardDeskL3Leg.png' },
	{ value:'custom_workspace', label:'Dual Workspace', img:'https://www.deskspace.in.th/wp-content/uploads/2026/01/CardCustomDeskDualWorkSpace.png' },
  ];
  host.innerHTML = '';
  TYPES.forEach(t=>{
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'dpb-type-card';
    card.setAttribute('data-value', t.value);
    card.setAttribute('aria-selected', sel.value === t.value ? 'true' : 'false');
    card.innerHTML = `
      <span class="dpb-type-card__chip"><img loading="lazy" alt="${t.label}" src="${t.img}"></span>
      <span class="dpb-type-card__name">${t.label}</span>
    `;
    card.addEventListener('click', ()=>{
      if(sel.value !== t.value){
        sel.value = t.value;
        [...host.querySelectorAll('.dpb-type-card')].forEach(el=>el.setAttribute('aria-selected','false'));
        card.setAttribute('aria-selected','true');
        sel.dispatchEvent(new Event('change', { bubbles:true }));
      }
    });
    host.appendChild(card);
  });
  sel.addEventListener('change', ()=>{
    const v = sel.value;
    [...host.querySelectorAll('.dpb-type-card')].forEach(el=>{
      el.setAttribute('aria-selected', el.getAttribute('data-value') === v ? 'true' : 'false');
    });
  });
  sel.dispatchEvent(new Event('change', { bubbles:false }));
})();

byId('wm-enable')?.addEventListener('change', e=>{
  DPB_toggleWatermark(e.target.checked);
  scheduleRedraw();
});

byId('wm-opacity')?.addEventListener('input', e=>{
  DPB_setWatermarkOptions({ opacity: +e.target.value });
  scheduleRedraw();
});

byId('wm-color-orig')?.addEventListener('click', ()=>{
  DPB_setWatermarkOptions({ original:true, black:false, white:false,  autoColor:false });
  scheduleRedraw();
});

byId('wm-color-black')?.addEventListener('click', ()=>{
  DPB_setWatermarkOptions({ original:false, black:true,  white:false, autoColor:false });
  scheduleRedraw();
});

byId('wm-color-white')?.addEventListener('click', ()=>{
  DPB_setWatermarkOptions({ original:false, black:false, white:true,  autoColor:false });
  scheduleRedraw();
});

byId('wm-debug')?.addEventListener('change', e=>{
  DPB_debugWatermark(e.target.checked);
  scheduleRedraw();
});

byId('dpb-form').addEventListener('input',  ()=>{ 
  validateInputs(); 
  try{ drawFooter(); }catch(_){}
  try{ measureInfoGrid(); }catch(_){}
  scheduleRedraw(); 
}, true);

byId('dpb-form').addEventListener('change', ()=>{ 
  validateInputs(); 
  try{ drawFooter(); }catch(_){}
  try{ measureInfoGrid(); }catch(_){}
  scheduleRedraw(); 
}, true);

byId('dpb-legs')?.addEventListener('change', ()=>{ 
  try{ drawFooter(); }catch(_){}
  try{ measureInfoGrid(); }catch(_){}
  scheduleRedraw(); 
});

document.querySelector('.dpb-wrap')
  .addEventListener('input',  ()=>{ validateInputs(); scheduleRedraw(); }, true);

document.querySelector('.dpb-wrap')
  .addEventListener('change', ()=>{ validateInputs(); scheduleRedraw(); }, true);

byId('dpb-preview-btn').addEventListener('click', ()=>{
  if(!validateInputs()) return;
  const targetH = measureTotalHeight();
  if(canvas.height !== targetH){ canvas.height = targetH; }
  draw(); drawFooter();
});

byId('dpb-download').addEventListener('click', (e)=>{
  e.preventDefault();
  if (!validateInputs()) return;
  const fname = buildCustomerDateFilename() + '.png';
  if (canvas.toBlob) {
    canvas.toBlob((blob)=>{
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a   = document.createElement('a');
      a.href = url;
      a.download = fname;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(()=> URL.revokeObjectURL(url), 1000);
    }, 'image/png');
  } else {
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = fname;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }
});

cartBody.addEventListener('scroll', ()=>{});

function buildCustomerDateFilename(){
  const sanitizeFilename = (name) => {
      return (name || '').replace(/[^a-z0-9\u0E00-\u0E7F\-_]/gi, '_');
  };

  const typeSel = document.getElementById('dpb-type');
  let typeName = 'Desk';
  if (typeSel && typeSel.selectedOptions[0]) {
      typeName = typeSel.selectedOptions[0].text;
  }
  typeName = typeName.replace(/\s*\(.*?\)/g, '').trim();
  const safeType = sanitizeFilename(typeName);
  
  const topKey = document.getElementById('dpb-top-color')?.value;
  let topName = topKey || 'Color';
  if (window.state && window.state.meta && window.state.meta.colors) {
      const colorObj = window.state.meta.colors.find(c => c.key === topKey);
      if (colorObj && colorObj.name) {
          topName = colorObj.name;
      }
  }
  const safeColor = sanitizeFilename(topName);

  let optPart = '';
  if (typeof totalSelectedCount === 'function') {
      if (totalSelectedCount() > 0) {
          optPart = '_Opt';
      }
  } else if (window.state && window.state.selectedOptions) {
      const total = Object.values(window.state.selectedOptions).reduce((sum, item) => sum + (item?.count || 0), 0);
      if (total > 0) optPart = '_Opt';
  }

  let yyyy, mm, dd;
  const d = document.getElementById('dpb-date').value;
   
  if (d) {
    [yyyy, mm, dd] = d.split('-');
  } else {
    const t = new Date();
    yyyy = String(t.getFullYear());
    mm   = String(t.getMonth()+1).padStart(2,'0');
    dd   = String(t.getDate()).padStart(2,'0');
  }
  const prettyDate = `${dd}-${mm}-${yyyy}`;
  
  return `${safeType}_${safeColor}${optPart}_${prettyDate}`;
}
function sanitizeFilename(name){
  return String(name)
    .replace(/[\\/:*?"<>|]+/g, '')   
    .replace(/\s+/g, '_')            
    .replace(/_+/g, '_')             
    .replace(/^_+|_+$/g, '')         
    .substring(0, 100);              
}

(function(){
  function dpb_updateLOnlyVisibility(){
    var tSel = byId && byId('dpb-type');
    var box  = byId && byId('dpb-l-only');
    if (!tSel || !box) return;
    var tval = (tSel.value || '').trim().toLowerCase();
    box.style.display = (tval === 'l2' || tval === 'l3') ? '' : 'none';
  }
  try{
    var tSel = byId && byId('dpb-type');
    if (tSel){
      tSel.addEventListener('change', dpb_updateLOnlyVisibility);
    }
  }catch(_){}
  try{ dpb_updateLOnlyVisibility(); }catch(_){}
  window.dpb_updateLOnlyVisibility = dpb_updateLOnlyVisibility;
})();

document.addEventListener('input',  ()=>{ try{ scheduleRedraw(); }catch(_){ } }, true);
document.addEventListener('change', ()=>{ try{ scheduleRedraw(); }catch(_){ } }, true);

(function wireActionsIntoCartMobileOnly(){
  function whenReady(selectors, cb){
    const ok = selectors.every(sel => document.querySelector(sel));
    if (ok) return void cb();
    requestAnimationFrame(()=> whenReady(selectors, cb));
  }
  whenReady(['#dpb-cart-panel', '#dpb-cart-panel .dpb-cart-footer', '.dpb-actions'], () => {
    const cartPanel   = document.getElementById('dpb-cart-panel');
    const cartFooter  = document.querySelector('#dpb-cart-panel .dpb-cart-footer');
    const actionsBar  = document.querySelector('.dpb-actions');
    const confirmBtn  = document.getElementById('dpb-cart-confirm');
    if(!cartPanel || !cartFooter || !actionsBar){
      console.warn('[DPB] wireActionsIntoCartMobileOnly: required elements not found');
      return;
    }
    let actionsHome = document.getElementById('dpb-actions-home');
    if(!actionsHome){
      actionsHome = document.createElement('div');
      actionsHome.id = 'dpb-actions-home';
      actionsBar.parentNode.insertBefore(actionsHome, actionsBar);
    }
    const mq = window.matchMedia('(max-width: 900px)');
    const isMobile = () => mq.matches;
    function scheduleCartHeightAfterDom(){
      requestAnimationFrame(()=> requestAnimationFrame(()=>{
        if (typeof requestMobileCartHeightUpdate === 'function'){
          requestMobileCartHeightUpdate();
        }
      }));
    }
    function moveActionsToCart(){
      if (!isMobile()) return;
      if (!cartPanel.classList.contains('is-open')) return;
      if (!cartFooter.contains(actionsBar)){
        cartFooter.appendChild(actionsBar);
        actionsBar.classList.add('dpb-actions--in-cart');
        confirmBtn?.classList.add('is-hidden');
        window.dispatchEvent(new CustomEvent('dpb:actions-moved-into-cart'));
      }else{
        confirmBtn?.classList.add('is-hidden');
      }
      scheduleCartHeightAfterDom();
    }
    function moveActionsBack(){
      if (actionsHome && actionsHome.parentNode && actionsHome.nextSibling !== actionsBar){
        actionsHome.parentNode.insertBefore(actionsBar, actionsHome.nextSibling);
        actionsBar.classList.remove('dpb-actions--in-cart');
      }
      confirmBtn?.classList.remove('is-hidden');
    }
    const onCartClassChange = () => {
      if (cartPanel.classList.contains('is-open') && isMobile()){
        moveActionsToCart();
      } else {
        moveActionsBack();
      }
    };
    const mo = new MutationObserver(onCartClassChange);
    mo.observe(cartPanel, { attributes:true, attributeFilter:['class'] });
    mq.addEventListener?.('change', onCartClassChange);
    window.addEventListener('dpb:actions-moved-into-cart', () => {
      scheduleCartHeightAfterDom();
    });
    onCartClassChange();
  });
})();

window.DPB_TYPE_DEFAULTS = window.DPB_TYPE_DEFAULTS || {
  custom: { mw: 70, ml: 180, aw: null, al: null, aside: 'right' }, 
  custom_single: { mw: 60, ml: 160, aw: null, al: null, aside: 'right' },
  custom_manual: { mw: 60, ml: 120, aw: null, al: null, aside: 'right' },
  single: { mw: 60,  ml: 100, aw: null, al: null, aside: 'right' }, 
  l2:     { mw: 70,  ml: 180, aw: 110, al: 70,   aside: 'right' }, 
  l3:     { mw: 80,  ml: 200, aw: 150, al: 80,   aside: 'right' }, 
    custom_workspace: { mw: 70, ml: 180, aw: null, al: null, aside: 'right' },
};

function getTypeDefaults(typeKey){
  const k = String(typeKey||'custom').toLowerCase();
  return DPB_TYPE_DEFAULTS[k] || DPB_TYPE_DEFAULTS.custom;
}

function getNumOrDefault(id, key){
  const el = byId(id);
  const v = Number(el?.value);
  if (Number.isFinite(v)) return v;
  const def = getTypeDefaults();
  return Number(def?.[key]);
}

function getAsideOrDefault(){
  const el = byId('dpb-aside');
  const val = (el?.value || '').toLowerCase();
  if (val === 'left' || val === 'right') return val;
  const def = getTypeDefaults();
  return (def?.aside || 'right');
}

// ============================================================
// [PART 6 MODIFIED] applyTypeDefaultsAndRefresh (เพิ่ม Flag Mute)
// ============================================================

function applyTypeDefaultsAndRefresh(typeKey) {
  // [NEW] เริ่มต้นการสลับ Type -> สั่ง Mute Popup
  window.__isSwitchingType = true;

  const d = getTypeDefaults(typeKey);
  const sel = document.getElementById('dpb-type');
  if (sel && sel.value !== typeKey) sel.value = typeKey;

  const setNum = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.value = (val == null) ? '' : String(val);
  };
  setNum('dpb-mw', d.mw);
  setNum('dpb-ml', d.ml);
  setNum('dpb-aw', d.aw);
  setNum('dpb-al', d.al);
  const aside = document.getElementById('dpb-aside');
  if (aside && d.aside) aside.value = d.aside;
  if (aside) aside.dispatchEvent(new Event('change', { bubbles: true }));
  const isL = (typeKey === 'l2' || typeKey === 'l3');
  const awWrap = document.querySelector('[for="dpb-aw"]')?.closest('div') || null;
  const alWrap = document.querySelector('[for="dpb-al"]')?.closest('div') || null;
  if (awWrap) awWrap.style.display = isL ? '' : 'none';
  if (alWrap) alWrap.style.display = isL ? '' : 'none';
  const edgeSel = document.getElementById('dpb-edge');
  if (edgeSel) edgeSel.value = 'rounded';
  
  document.body.classList.remove('dpb-virtual-square');

  const allRadiusInputs = [
    'r_rect_tl', 'r_rect_tr', 'r_rect_bl', 'r_rect_br',
    'ld_r_tl', 'ld_r_tr', 'ld_r_step', 'ld_r_br', 'ld_r_armbl', 'ld_r_armbr',
    'dpb-rInner'
  ];
  allRadiusInputs.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = ''; 
  });
  if (typeof syncEdgeTilesActiveState === 'function') syncEdgeTilesActiveState();
  if (typeof syncRBlocks === 'function') syncRBlocks();
  if (typeof buildOptions === 'function') buildOptions();
  if (typeof buildOptConfig === 'function') buildOptConfig();
  if (typeof scheduleRedraw === 'function') scheduleRedraw();

  // [NEW] คืนค่า Flag หลังจากผ่านไป 500ms (เพื่อให้แน่ใจว่าค่าทุกอย่างนิ่งแล้ว)
  if (window._typeSwitchTimer) clearTimeout(window._typeSwitchTimer);
  window._typeSwitchTimer = setTimeout(() => {
      window.__isSwitchingType = false;
      // Optional: สั่ง Validate อีกรอบหลังคืนค่าเผื่อมี Error จริงๆ ที่ค้างอยู่
      if(typeof validateInputs === 'function') validateInputs();
  }, 1000);
}
	
function syncTypeTilesActive(typeKey){
  const host = document.getElementById('dpb-type-tiles');
  if(!host) return;
  host.querySelectorAll('.dpb-type-card[aria-selected="true"]').forEach(n=>{
    n.setAttribute('aria-selected','false');
    n.classList.remove('is-active');
  });
  const card = host.querySelector(`.dpb-type-card[data-type="${CSS.escape(typeKey)}"]`);
  if (card){
    card.setAttribute('aria-selected','true');
    card.classList.add('is-active');
  }
}

(function ensureTypeDefaultBinding(){
  const sel = document.getElementById('dpb-type');
  if(!sel || sel.dataset.bound === '1') return;
  sel.dataset.bound = '1';
  sel.addEventListener('change', ()=>{
    const key = sel.value;
    syncTypeTilesActive(key);
    applyTypeDefaultsAndRefresh(key);
  });
})();

(function bindTypeTiles(){
  const host = document.getElementById('dpb-type-tiles');
  const sel  = document.getElementById('dpb-type');
  if(!host || !sel || host.dataset.bound === '1') return;
  host.dataset.bound = '1';
  const activate = (card)=>{
    const key = card?.dataset?.type;
    if(!key) return;
    if (sel.value !== key){
      sel.value = key;
    }
    sel.dispatchEvent(new Event('change', { bubbles:true }));
  };
  host.addEventListener('click', (e)=>{
    const card = e.target.closest('.dpb-type-card');
    if(!card || !host.contains(card)) return;
    activate(card);
  }, true);
  host.addEventListener('keydown', (e)=>{
    if(e.key !== 'Enter' && e.key !== ' ') return;
    const card = e.target.closest('.dpb-type-card');
    if(!card || !host.contains(card)) return;
    e.preventDefault();
    activate(card);
  }, true);
})();

	function getTypeOptionsMeta(){
 const sel = document.getElementById('dpb-type');
 if(!sel) return { order:[], byValue:new Map(), byLabel:new Map() };
 const order = [...sel.options].map(o => ({
  value: o.value,
 label: (o.textContent || '').trim()
  }));
  const byValue = new Map(order.map(o => [o.value.toLowerCase(), o]));
  const byLabel = new Map(order.map(o => [o.label.toLowerCase(), o]));
 return { order, byValue, byLabel };
}

function renderOrSyncTypeTiles(){
  const host = document.getElementById('dpb-type-tiles');
  const sel  = document.getElementById('dpb-type');
  if(!host || !sel) return;
  const { order, byValue, byLabel } = getTypeOptionsMeta();
  if (host.children.length === 0){
    host.innerHTML = order.map(e=>`
      <div class="dpb-type-card" data-type="${e.value}" aria-selected="false" tabindex="0">
        <span class="dpb-type-card__chip"></span>
        <span class="dpb-type-card__name">${e.label}</span>
      </div>
    `).join('');
  }else{
    const cards = [...host.querySelectorAll('.dpb-type-card')];
    cards.forEach(card=>{
      let t = (card.dataset.type || '').trim().toLowerCase();
      const nameEl = card.querySelector('.dpb-type-card__name');
      const labelText = (nameEl?.textContent || '').trim();
      if (t && byValue.has(t)) {
        if (nameEl && !labelText) nameEl.textContent = byValue.get(t).label;
        return;
      }
      if (labelText) {
        const hit = byLabel.get(labelText.toLowerCase());
        if (hit){
          card.dataset.type = hit.value;   
          return;
        }
      }
      card.dataset.type = sel.value || 'custom';
      if (nameEl && !labelText) {
        const meta = byValue.get((sel.value||'custom').toLowerCase());
        if (meta) nameEl.textContent = meta.label;
      }
    });
  }
  syncTypeTilesActive(sel.value || 'custom');
}

(function bootTypeTilesOnce(){
  renderOrSyncTypeTiles();
  const sel = document.getElementById('dpb-type');
  if (sel && !sel.dataset._typeBootDone){
    sel.dataset._typeBootDone = '1';
    sel.dispatchEvent(new Event('change', { bubbles:true }));
  }
})();

try {
  const metaStatus = await loadMeta();
  applyTypeDefaults();
  validateInputs();
  const chkShowLegs = document.getElementById('dpb-show-legs');
  state.flags = state.flags || {}; 
  window.state = state; 
  if (chkShowLegs) {
      if (typeof state.flags.showLegs !== 'undefined') {
        chkShowLegs.checked = !!state.flags.showLegs;
      } else {
        state.flags.showLegs = !!chkShowLegs.checked;
      }
  }
  scheduleRedraw();
  if(metaStatus && metaStatus.usedCache){
    showStatusMessage('ใช้ข้อมูลล่าสุดที่บันทึกไว้ (ไม่สามารถเชื่อมต่อ API ได้)', '#b45309');
  } else {
    showStatusMessage('');
  }
} catch(err) {
  console.error('[Deskspace Proposal Builder] Metadata request failed', err);
  showStatusMessage('โหลดข้อมูลไม่สำเร็จ: '+err.message, '#b91c1c');
}


function hidePreloadWhenMetaReady(meta){
  if(!PRELOAD_EL) return;
  const ok = meta
    && Array.isArray(meta.colors) && meta.colors.length > 0
    && Array.isArray(meta.options) && meta.options.length > 0;
  if(ok) hidePreloadNow();
}


byId('dpb-top-color')?.addEventListener('change', ()=>{
  if (!state?.theme?.userPickedIn){
    applyAutoInColorIfNeeded();
  }
  if(typeof scheduleRedraw==='function') scheduleRedraw();
});

function scheduleRedraw(){
  if (window._rafRedraw) return;
  window._rafRedraw = requestAnimationFrame(()=>{
    window._rafRedraw = null;
    const okInputs = validateInputs();
    const okPlace  = validateOptionPlacements();
    const isOK     = !!(okInputs && okPlace);
    state.validation = state.validation || { ok:true, messages:[] };
    state.validation.ok = isOK;
    if (isOK){
      try{ dpb_applyAutoInColorIfNeeded(); }catch(_){}
      const targetH = measureTotalHeight();
      if (canvas.height !== targetH){ canvas.height = targetH; }
      draw();
      try{ DPB_applyWatermarkAutoColor(); }catch(_){}
      try{ DPB_drawBrandWatermark_OnTop(); }catch(_){}
    }
    try{ drawFooter(); }catch(e){}
  });
}

})(); 


(function(){
  var a = document.getElementById('dpb-gapA');
  var b = document.getElementById('dpb-gapB');
  function kick(){
    if (typeof window.scheduleRedraw === 'function') {
      scheduleRedraw();
    }
  }
  ['input','change'].forEach(function(ev){
    if (a) a.addEventListener(ev, kick);
    if (b) b.addEventListener(ev, kick);
  });
})();

window.DPB_DEBUG = window.DPB_DEBUG ?? false;

(function initStickyFooter(){
    const btnDown = document.getElementById('dpb-footer-download');
    if(btnDown){
        btnDown.addEventListener('click', (e)=>{
            const origBtn = document.getElementById('dpb-download');
            if(origBtn) origBtn.click();
            else alert('Function Download not found');
        });
    }
    const btnTheme = document.getElementById('dpb-footer-theme');
    if(btnTheme){
        btnTheme.addEventListener('click', ()=>{
             if(typeof openTheme === 'function') openTheme();
             else {
                 const panel = document.getElementById('dpb-theme-panel');
                 if(panel) panel.classList.add('is-open');
             }
        });
    }
    const btnCart = document.getElementById('dpb-footer-cart-btn');
    if(btnCart){
        btnCart.addEventListener('click', ()=>{
             if(typeof openCart === 'function') openCart();
             else {
                 const cart = document.getElementById('dpb-cart-panel');
                 if(cart) cart.classList.add('is-open');
             }
        });
    }
    const origBadge = document.getElementById('dpb-cart-count'); 
    const footerBadge = document.getElementById('dpb-footer-count');
    if(origBadge && footerBadge){
        const obs = new MutationObserver(()=>{
            footerBadge.textContent = origBadge.textContent;
            footerBadge.style.display = (origBadge.textContent === '0') ? 'none' : 'flex';
        });
        obs.observe(origBadge, { childList: true, characterData: true, subtree: true });
        footerBadge.textContent = origBadge.textContent;
        footerBadge.style.display = (origBadge.textContent === '0') ? 'none' : 'flex';
    }
})();
(function initCompleteImagePopupSystem() {
    const isMobileScreen = () => window.matchMedia("(max-width: 768px)").matches;
    const canvas = document.getElementById('dpb-canvas');
    if (!canvas) return;
    const wrapper = canvas.closest('.dpb-canvas-wrap') || document.querySelector('.dpb-canvas-wrap') || canvas.parentElement;

    // --- ตัวแปรสำหรับระบบ Zoom ---
    let currentScale = 1;
    let currentTranslateX = 0;
    let currentTranslateY = 0;
    
    function resetZoomState(img) {
        currentScale = 1;
        currentTranslateX = 0;
        currentTranslateY = 0;
        if(img) img.style.transform = `translate(0px, 0px) scale(1)`;
    }

    function openImageModal() {
        const modal = document.getElementById('dpb-image-modal');
        const img = modal?.querySelector('img');
        if (!canvas || !img || !modal) return;
        
        img.src = canvas.toDataURL('image/png');
        resetZoomState(img);

        modal.style.display = 'flex';
        requestAnimationFrame(() => {
            modal.style.opacity = '1';
        });
        document.body.style.overflow = 'hidden';
    }

    if (!document.getElementById('dpb-image-modal')) {
        const modal = document.createElement('div');
        modal.id = 'dpb-image-modal';
        Object.assign(modal.style, {
            position: 'fixed', top: '0', left: '0', width: '100%', height: '100%',
            backgroundColor: 'rgba(0,0,0,0.85)', zIndex: '999999999', display: 'none',
            justifyContent: 'center', alignItems: 'center', opacity: '0', transition: 'opacity 0.3s ease',
            overflow: 'hidden',
            touchAction: 'none' 
        });

        // ==========================================
        // [เพิ่มใหม่] : ป้องกันคลิกขวาที่ตัว Modal
        // ==========================================
        modal.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        const img = document.createElement('img');
        Object.assign(img.style, {
            maxWidth: '95vw', maxHeight: '95vh', objectFit: 'contain', 
            transformOrigin: 'center center',
            cursor: 'grab',
            touchAction: 'none',
            transition: 'transform 0.1s ease-out',
            // ป้องกัน User ลากรูปออกไปวางข้างนอก (Drag & Drop image)
            userSelect: 'none',
            webkitUserDrag: 'none' 
        });
        
        // เพิ่มป้องกันคลิกขวาที่ตัวรูปด้วย (เพื่อความชัวร์)
        img.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        modal.appendChild(img);

        // --- Logic การ Zoom และ Pan (เหมือนเดิม) ---
        let startDist = 0;
        let startScale = 1;
        let startX = 0;
        let startY = 0;
        let isDragging = false;

        const getDistance = (touches) => {
            return Math.hypot(touches[0].pageX - touches[1].pageX, touches[0].pageY - touches[1].pageY);
        };

        img.addEventListener('touchstart', (e) => {
            if (e.touches.length === 2) {
                startDist = getDistance(e.touches);
                startScale = currentScale;
            } else if (e.touches.length === 1 && currentScale > 1) {
                isDragging = true;
                startX = e.touches[0].pageX - currentTranslateX;
                startY = e.touches[0].pageY - currentTranslateY;
                img.style.cursor = 'grabbing';
                img.style.transition = 'none';
            }
        });

        img.addEventListener('touchmove', (e) => {
            if(e.cancelable) e.preventDefault(); 

            if (e.touches.length === 2) {
                const dist = getDistance(e.touches);
                let newScale = (dist / startDist) * startScale;
                newScale = Math.min(Math.max(1, newScale), 5); 
                currentScale = newScale;
                
                if (currentScale === 1) {
                    currentTranslateX = 0;
                    currentTranslateY = 0;
                }
                img.style.transform = `translate(${currentTranslateX}px, ${currentTranslateY}px) scale(${currentScale})`;

            } else if (e.touches.length === 1 && isDragging && currentScale > 1) {
                currentTranslateX = e.touches[0].pageX - startX;
                currentTranslateY = e.touches[0].pageY - startY;
                img.style.transform = `translate(${currentTranslateX}px, ${currentTranslateY}px) scale(${currentScale})`;
            }
        });

        img.addEventListener('touchend', () => {
            isDragging = false;
            img.style.cursor = 'grab';
            img.style.transition = 'transform 0.1s ease-out';
            if (currentScale < 1) {
                currentScale = 1;
                currentTranslateX = 0;
                currentTranslateY = 0;
                img.style.transform = `translate(0px, 0px) scale(1)`;
            }
        });
        // -----------------------------------------------------

        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
        Object.assign(closeBtn.style, {
            position: 'absolute', top: '20px', left: '20px',
            background: 'none', border: 'none',
            color: '#fff', fontSize: '25px', cursor: 'pointer', zIndex: '1000000'
        });

        const saveBtn = document.createElement('button');
        saveBtn.id = 'dpb-popup-save-btn'; 
        saveBtn.innerHTML = '<i class="fas fa-download"></i>';
        Object.assign(saveBtn.style, {
            position: 'absolute', top: '20px', right: '20px',
            background: 'none', border: 'none',
            color: '#fff', fontSize: '20px', cursor: 'pointer', zIndex: '1000000'
        });

        modal.appendChild(closeBtn);
        modal.appendChild(saveBtn);
        document.body.appendChild(modal);

        const closeModal = () => {
            modal.style.opacity = '0';
            setTimeout(() => {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                resetZoomState(img);
            }, 300);
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            } else if (e.target === img && currentScale === 1 && !isDragging) {
                closeModal(); 
            }
        });

        saveBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const link = document.createElement('a');
            const fname = (typeof buildCustomerDateFilename === 'function') ? buildCustomerDateFilename() : 'deskspace-layout';
            link.download = fname + '.png';
            link.href = img.src;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    let btn = wrapper.querySelector('.dpb-btn-popup');
    if (!btn) {
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dpb-btn-popup';
        btn.title = 'ขยายภาพ';
        const isMobile = isMobileScreen();
        Object.assign(btn.style, {
            position: 'absolute', bottom: '12px', right: '12px', zIndex: '100',
            width: isMobile ? '22px' : '36px', height: isMobile ? '22px' : '36px',
            padding: isMobile ? '3px' : '8px',
            background: 'rgba(0, 0, 0, 0.6)', color: '#fff', border: 'none',
            borderRadius: '6px', cursor: 'pointer', display: 'flex',
            alignItems: 'center', justifyContent: 'center', transition: 'background 0.2s'
        });
        btn.onmouseenter = () => { btn.style.background = 'rgba(0, 0, 0, 0.8)'; };
        btn.onmouseleave = () => { btn.style.background = 'rgba(0, 0, 0, 0.6)'; };
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>`;
        if (getComputedStyle(wrapper).position === 'static') { wrapper.style.position = 'relative'; }
        wrapper.appendChild(btn);
    }

    const finalBtn = wrapper.querySelector('.dpb-btn-popup');
    const newBtn = finalBtn.cloneNode(true);
    finalBtn.parentNode.replaceChild(newBtn, finalBtn);

    newBtn.addEventListener('click', () => {
        openImageModal();
    }, false);
})();

			
(function initRadiusAutoSwitcher() {
  const inputsToCheck = [
    'r_rect_tl', 'r_rect_tr', 'r_rect_bl', 'r_rect_br',
    'ld_r_tl', 'ld_r_tr', 'ld_r_step', 'ld_r_br', 'ld_r_armbl', 'ld_r_armbr'
  ];

  function handleInput(e) {
    if (!inputsToCheck.includes(e.target.id)) return;
    let allZero = true;
    let hasVisibleInputs = false;

    inputsToCheck.forEach(id => {
      const el = document.getElementById(id);
      if (el && el.offsetParent !== null) {
        hasVisibleInputs = true;
        const val = parseFloat(el.value);
        if (!isNaN(val) && val > 0) {
          allZero = false;
        }
      }
    });

    if (!hasVisibleInputs) return;

    const body = document.body;
    const wasVirtual = body.classList.contains('dpb-virtual-square');

    if (allZero) {
      if (!wasVirtual) {
        body.classList.add('dpb-virtual-square');
        if (typeof syncEdgeTilesActiveState === 'function') syncEdgeTilesActiveState();
      }
    } else {
      if (wasVirtual) {
        body.classList.remove('dpb-virtual-square');
       
        const edgeSel = document.getElementById('dpb-edge');
        if (edgeSel && edgeSel.value === 'square') {
            edgeSel.value = 'rounded';
        }
        
        if (typeof syncEdgeTilesActiveState === 'function') syncEdgeTilesActiveState();
      }
    }
  }

  document.addEventListener('input', handleInput);
  document.addEventListener('change', handleInput);
  
  setTimeout(() => {
      const firstInput = document.getElementById(inputsToCheck[0]);
      if(firstInput) handleInput({ target: firstInput });
  }, 500);
})();

			
(function fixFooterCartAction(){
  const footerBtn = document.getElementById('dpb-footer-cart-btn');
  if(footerBtn){
      const newBtn = footerBtn.cloneNode(true);
      footerBtn.parentNode.replaceChild(newBtn, footerBtn);

      newBtn.addEventListener('click', (e)=>{
          e.preventDefault();
          e.stopPropagation();
          if (typeof scrollToTopSmooth === 'function') {
              scrollToTopSmooth();
          } else {
              window.scrollTo({ top: 0, behavior: 'smooth' });
          }
          if(typeof buildOptConfig === 'function') {
              buildOptConfig();
          }
          if(typeof openCart === 'function'){
              openCart();
          } else if(typeof window.openCart === 'function'){
              window.openCart();
          } else {
              const panel = document.getElementById('dpb-cart-panel');
              const empty = document.getElementById('dpb-cart-empty');
              const body  = document.getElementById('dpb-cart-body');
              if(panel) {
                  panel.classList.add('is-open');
                  if(body && empty){
                      const hasItems = body.children.length > 0;
                      empty.style.display = hasItems ? 'none' : 'block';
                      body.style.display  = hasItems ? 'flex' : 'none';
                  }
                  document.body.classList.add('dpb-cart-lock');
              }
          }
      });
      console.log(' Footer Cart Button Linked (With Scroll)');
  }
})();


			
document.addEventListener('DOMContentLoaded', function() {
    // 1. สั่งให้ปุ่มใหม่เปิดหน้าต่าง Cart
    const customCartBtn = document.getElementById('dpb-custom-cart-btn');
    if(customCartBtn) {
        customCartBtn.addEventListener('click', function() {
            // เรียกใช้ Logic เดิมของธีมในการเปิด Cart
            const cartPanel = document.getElementById('dpb-cart-panel');
            const backdrop = document.getElementById('dpb-cart-backdrop');
            
            if(cartPanel) cartPanel.classList.add('is-open');
            if(backdrop) backdrop.classList.add('is-active');
        });
    }

    // 2. เชื่อมตัวเลข (Count) จากปุ่มเดิมมาแสดงที่ปุ่มใหม่
    const originalBadge = document.getElementById('dpb-cart-count');
    const newBadge = document.getElementById('dpb-custom-cart-count');

    if(originalBadge && newBadge) {
        // สร้างตัวตรวจจับการเปลี่ยนแปลง (Observer)
        const observer = new MutationObserver(function(mutations) {
            const count = originalBadge.innerText || '0';
            newBadge.innerText = count;
            // ซ่อนถ้าเป็น 0 แสดงถ้ามากกว่า 0
            newBadge.style.display = (count !== '0') ? 'inline-block' : 'none';
        });
        
        // เริ่มจับตาดูการเปลี่ยนแปลงที่ปุ่ม Cart เดิม
        observer.observe(originalBadge, { childList: true, subtree: true, characterData: true });
    }
});			
		 
		 

 </script>
			
		
<div id="ptr-spinner" class="ptr-wrap">
    <svg class="ptr-icon" viewBox="0 0 24 24">
        <path d="M12 4V2M12 22v-2M4 12H2M22 12h-2M4.93 4.93L3.51 3.51M20.49 20.49l-1.42-1.42M4.93 19.07l-1.42 1.42M20.49 3.51l-1.42 1.42" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    </svg>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. เช็คขนาดหน้าจอ
    if (window.innerWidth > 991) return;

    const ptrWrap = document.getElementById('ptr-spinner');
    if (!ptrWrap) return;

    // --- [เพิ่ม] ระบุตัว Sidebar ที่เราต้องการเช็คค่า Scroll ---
    const targetSidebar = document.querySelector('.dpb-sidebar-panel');

    const config = {
        ptrThreshold: 120
    };

    let state = {
        startY: 0,
        startX: 0,
        isPulling: false,
        isRefreshing: false,
        isTouching: false,
        isAngleLocked: false
    };

    const getScrollParent = (node) => {
        if (node == null) return null;
        if (node === document.body || node === document.documentElement) return null;
        const style = window.getComputedStyle(node);
        const overflowY = style.getPropertyValue('overflow-y');
        const isScrollable = overflowY === 'auto' || overflowY === 'scroll';
        if (isScrollable && node.scrollHeight > node.clientHeight) {
            return node;
        }
        return getScrollParent(node.parentNode);
    };

    const resetState = () => {
        state.isPulling = false;
        state.isTouching = false;
        state.isAngleLocked = false;
        
        ptrWrap.style.transition = 'transform 0.3s cubic-bezier(0.25, 0.8, 0.5, 1)';
        ptrWrap.style.transform = 'translate(-50%, -50px) scale(0)';
        ptrWrap.classList.remove('is-ready');
        ptrWrap.classList.remove('is-loading');
    };

    // --- Touch Start ---
    document.addEventListener('touchstart', function(e) {
        if (state.isRefreshing) return;

        // 1. เช็คว่าจุดที่นิ้วแตะ อยู่ในกล่องย่อยที่มี Scroll หรือไม่
        const scrollParent = getScrollParent(e.target);
        if (scrollParent && scrollParent.scrollTop > 0) {
            state.isTouching = false;
            return;
        }

        // 2. [สำคัญมาก] เช็ค Sidebar เป้าหมายของคุณ
        // ถ้า Sidebar ยังเลื่อนลงมาอยู่ (scrollTop > 0) ให้ "ยกเลิก" การรีเฟรช
        // เพื่อให้คุณสามารถเลื่อน stage-panel เพื่อดัน sidebar ขึ้นไปได้
        if (targetSidebar && targetSidebar.scrollTop > 0) {
            state.isTouching = false;
            return;
        }

        // 3. เช็ค Scroll หลักของหน้าเว็บ
        if (window.scrollY > 0) {
            state.isTouching = false;
            return;
        }

        state.isTouching = true;
        state.startY = e.touches[0].clientY;
        state.startX = e.touches[0].clientX;
        state.isPulling = false;
        state.isAngleLocked = false;
        
        ptrWrap.style.transition = 'none';
    }, { passive: true });

    // --- Touch Move ---
    document.addEventListener('touchmove', function(e) {
        if (!state.isTouching) return;

        const currentY = e.touches[0].clientY;
        const currentX = e.touches[0].clientX;
        const diffY = currentY - state.startY;
        const diffX = Math.abs(currentX - state.startX);

        // เช็คซ้ำอีกรอบ: ถ้า Sidebar ขยับลงมาแล้ว ให้ยกเลิกการดึง
        if (targetSidebar && targetSidebar.scrollTop > 0) {
            state.isTouching = false;
            resetState(); // ดีดลูกศรกลับทันที
            return;
        }

        if (window.scrollY > 0) {
            state.isTouching = false;
            return;
        }

        // ล็อคทิศทาง
        if (!state.isAngleLocked) {
            if (diffX > Math.abs(diffY)) {
                state.isTouching = false; 
                return;
            }
            state.isAngleLocked = true;
        }

        // ดึงลง (diffY > 0) และ Sidebar ต้องอยู่บนสุด (scrollTop 0)
        if (diffY > 0) { 
            if(e.cancelable) e.preventDefault();

            state.isPulling = true;
            
            const moveY = Math.pow(diffY, 0.85) * 0.6; 
            const limitY = Math.min(moveY, config.ptrThreshold + 50);

            ptrWrap.style.transform = `translate(-50%, ${limitY}px) scale(1)`;

            const icon = ptrWrap.querySelector('.ptr-icon');
            if(!ptrWrap.classList.contains('is-ready')) {
                 if(icon) icon.style.transform = `rotate(${Math.min(diffY * 1.5, 360)}deg)`;
            }

            if (diffY > config.ptrThreshold) {
                ptrWrap.classList.add('is-ready');
            } else {
                ptrWrap.classList.remove('is-ready');
            }
        } 
    }, { passive: false });

    // --- Touch End ---
    document.addEventListener('touchend', function(e) {
        if (!state.isTouching) return;
        
        const currentY = e.changedTouches[0].clientY;
        const totalDiff = currentY - state.startY;

        // เงื่อนไขสุดท้ายก่อนรีเฟรช: ต้องดึงถึงระยะ และ Sidebar ต้องอยู่บนสุดจริงๆ
        const isSidebarTop = targetSidebar ? targetSidebar.scrollTop <= 0 : true;

        if (state.isPulling && totalDiff > config.ptrThreshold && isSidebarTop) {
            state.isRefreshing = true;
            
            ptrWrap.classList.add('is-loading');
            ptrWrap.style.transition = 'transform 0.3s ease';
            ptrWrap.style.transform = `translate(-50%, 60px) scale(1)`;
            
            setTimeout(() => {
                window.location.reload(); 
            }, 600);
            
        } else {
            resetState();
        }
        state.isTouching = false;
    });

    document.addEventListener('touchcancel', resetState);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // =======================================================
    // ส่วนที่ 1: ปิดการคลิกขวา (คงเดิม)
    // =======================================================
    var canvasElement = document.getElementById('dpb-canvas');
    if (canvasElement) {
        canvasElement.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
    }

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stagePanel = document.querySelector('.dpb-stage-panel');
    const sidebarPanel = document.querySelector('.dpb-sidebar-panel');

    if (!stagePanel || !sidebarPanel) return;

    // --- Configuration ---
    const friction = 0.96; 
    const multiplier = 1.2; 

    // --- State Variables ---
    let isDragging = false;
    let startY = 0;
    let lastY = 0;
    let currentScrollTop = 0;
    let velocity = 0;
    let rafId = null;
    let lastTime = 0;
    let maxScroll = 0; 

    // ฟังก์ชันหยุดทุกการเคลื่อนไหวทันที (Kill Switch)
    const stopMomentum = () => {
        if(rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        velocity = 0;
        isDragging = false;
    };

    // ฟังก์ชันอัปเดตหน้าจอ (Render Loop)
    const updateScroll = () => {
        if (Math.abs(velocity) > 0.1) {
            currentScrollTop -= velocity;
            
            // Limit ขอบเขต
            if (currentScrollTop < 0) {
                currentScrollTop = 0;
                velocity = 0; 
            } else if (currentScrollTop > maxScroll) {
                currentScrollTop = maxScroll;
                velocity = 0; 
            }

            sidebarPanel.scrollTop = currentScrollTop;

            if (!isDragging) {
                velocity *= friction;
                rafId = requestAnimationFrame(updateScroll);
            }
        } else {
            stopMomentum();
        }
    };

    // ============================================================
    // 1. จัดการ Event บน Stage Panel (พื้นที่โมเดล)
    // ============================================================
    
    stagePanel.addEventListener('touchstart', function(e) {
        stopMomentum(); // หยุดของเก่าก่อนเสมอ
        
        isDragging = true;
        startY = e.touches[0].clientY;
        lastY = startY;
        lastTime = performance.now();
        
        // Update ค่าล่าสุดจาก Sidebar จริงๆ (เผื่อมีการเลื่อนที่ Sidebar มาก่อน)
        currentScrollTop = sidebarPanel.scrollTop;
        maxScroll = sidebarPanel.scrollHeight - sidebarPanel.clientHeight;

    }, { passive: true });

    stagePanel.addEventListener('touchmove', function(e) {
        if (!isDragging) return;
        if (e.cancelable) e.preventDefault();

        const currentY = e.touches[0].clientY;
        const now = performance.now();
        const dt = now - lastTime;
        const deltaY = (currentY - lastY) * multiplier;

        currentScrollTop -= deltaY;

        // Clamp
        if (currentScrollTop < 0) currentScrollTop = 0;
        if (currentScrollTop > maxScroll) currentScrollTop = maxScroll;
        
        sidebarPanel.scrollTop = currentScrollTop;

        if (dt > 0) {
            const newVelocity = deltaY; 
            velocity = (velocity * 0.2) + (newVelocity * 0.8);
        }

        lastY = currentY;
        lastTime = now;
    }, { passive: false });

    stagePanel.addEventListener('touchend', function(e) {
        isDragging = false;
        if (Math.abs(velocity) > 0.5) {
            updateScroll();
        }
    });

    stagePanel.addEventListener('touchcancel', stopMomentum);

    // ============================================================
    // 2. [สำคัญ] แก้ปัญหาจอสั่น (เพิ่ม Event ที่ Sidebar)
    // ============================================================
    
    // เมื่อนิ้วแตะที่ Sidebar Panel (ตัวมันเอง)
    // ให้สั่งหยุด Momentum ที่มาจาก Stage Panel ทันที!
    sidebarPanel.addEventListener('touchstart', function() {
        stopMomentum();
        // อัปเดตค่า currentScrollTop ให้ตรงกับปัจจุบัน 
        // เผื่อผู้ใช้สลับกลับไปลากที่ Stage Panel จะได้ต่อเนื่อง
        currentScrollTop = sidebarPanel.scrollTop;
    }, { passive: true });

    // รองรับ Wheel บน Sidebar ด้วย (เผื่อใช้เมาส์เบรค)
    sidebarPanel.addEventListener('wheel', function() {
        stopMomentum();
        currentScrollTop = sidebarPanel.scrollTop;
    }, { passive: true });


    // ============================================================
    // 3. Wheel Support บน Stage Panel (Desktop)
    // ============================================================
    stagePanel.addEventListener('wheel', function(e) {
        if (sidebarPanel.scrollHeight > sidebarPanel.clientHeight) {
            e.preventDefault();
            stopMomentum(); // ใช้เมาส์ก็ต้องหยุด Momentum เก่า
            sidebarPanel.scrollTop += e.deltaY;
            currentScrollTop = sidebarPanel.scrollTop;
        }
    }, { passive: false });
});		
</script>		


<script>
window.DSLOG_Traffic_Info = {
    source: 'Direct',
    device: 'Desktop'
};

(function() {
    if (typeof window.DSLOG_V7_Config === 'undefined') return;

    const ref = document.referrer ? document.referrer.toLowerCase() : '';
    let source = 'Direct / None';
    if (ref.includes('google')) source = 'Google';
    else if (ref.includes('facebook') || ref.includes('fbclid')) source = 'Facebook';
    else if (ref.includes('line')) source = 'Line';
    else if (ref.includes('deskspace.in.th')) source = 'Internal';
    else if (ref.length > 5) {
        try { source = new URL(ref).hostname; } catch(e) { source = 'Other Web'; }
    }

    const ua = navigator.userAgent;
    let device = 'Desktop';
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua)) {
        device = 'Mobile';
    }

    window.DSLOG_Traffic_Info = { source, device };

    // ── Record Visit ─────────────────────────────────────────────────────────
    // [FIX A] ส่ง action ใน FormData body (ไม่ใช่ query string)
    const visitFd = new FormData();
    visitFd.append('action', 'DSLOG_Deskspace_record_visit');
    visitFd.append('nonce',  window.DSLOG_V7_Config.nonce);
    visitFd.append('source', source);
    visitFd.append('device', device);

    fetch(window.DSLOG_V7_Config.url, {
        method: 'POST',
        body:   visitFd
    }).catch(e => console.log('Visit Log Error:', e));
})();


// ─────────────────────────────────────────────────────────────────────────────
async function DSLOG_Deskspace_collectAndSave() {
    if (typeof window.DSLOG_V7_Config === 'undefined') return;

    try {
        

        const getEl      = (id) => document.getElementById(id);
        const getVal     = (id) => { const el = getEl(id); return (el && el.value) ? el.value : ''; };
        const getTxt     = (id) => { const el = getEl(id); return (el && el.selectedOptions && el.selectedOptions[0]) ? el.selectedOptions[0].text.trim() : ''; };
        const getNum     = (id) => { const val = getVal(id); return val === '' ? '-' : val; };
        const getLabelTxt= (id) => { const el = getEl(id); return (el) ? el.innerText.trim() : ''; };

        function checkLegGapModified() {
            const type = getVal('dpb-type').trim();
            const len  = parseFloat(getVal('dpb-ml')) || 0;
            const valA = parseFloat(getVal('dpb-gapA')) || 0;
            const valB = parseFloat(getVal('dpb-gapB')) || 0;
            let def = 5;
            if (type === 'l2') {
                if (len >= 120 && len <= 180) def = 5;
                else if (len >= 181 && len <= 190) def = 15;
                else if (len >= 191 && len <= 200) def = 25;
            }
            const isChanged = (Math.abs(valA - def) > 0.1) || (Math.abs(valB - def) > 0.1);
            if (isChanged) console.log("DSLOG: Gap Changed Detected", {valA, valB, def});
            return isChanged;
        }

        function checkLegCollisionWarning() {
            const notes = document.querySelectorAll('.dpb-field-note, .dpb-error-msg, .dpb-warning-text');
            for (let note of notes) {
                const txt = (note.textContent || '').toLowerCase();
                if (txt.includes('ขาโต๊ะ') || txt.includes('ทับ') || txt.includes('ชน')) {
                    return true;
                }
            }
            const toasts = document.querySelectorAll('.dpb-toast-msg, .dpb-alert-box');
            for (let t of toasts) {
                const txt = (t.textContent || '').toLowerCase();
                if (txt.includes('ขาโต๊ะ') || txt.includes('ทับ') || txt.includes('ชน')) {
                    return true;
                }
            }
            return false;
        }

        const leg_spacing = {};
        const valA = getNum('dpb-gapA');
        const valB = getNum('dpb-gapB');
        if (valA !== '-') leg_spacing[getLabelTxt('dpb-gapA-label') || 'ระยะ A'] = valA;
        if (valB !== '-') leg_spacing[getLabelTxt('dpb-gapB-label') || 'ระยะ B'] = valB;

        const customer = {
            "ชื่อ":         getVal('dpb-customer') || 'ไม่ระบุ',
            "แพลตฟอร์ม":    getVal('dpb-platforms') || 'ไม่ระบุ',
            "เบอร์โทร":     getVal('dpb-phone') || '-',
            "วันที่เลือก":  getVal('dpb-date') || '-'
        };

        const desk_spec = {
            "ประเภท":              getTxt('dpb-type'),
            "สีท็อป":              getTxt('dpb-top-color'),
            "รุ่นขาโต๊ะ":         getTxt('dpb-legs'),
            "ความกว้าง_Main_cm":  getNum('dpb-mw'),
            "ความยาว_Main_cm":    getNum('dpb-ml'),
            "ความกว้าง_L_cm":     getNum('dpb-aw'),
            "ความยาว_L_cm":       getNum('dpb-al'),
            "ทิศด้าน_L":          getVal('dpb-aside') === 'left' ? 'ซ้าย' : (getVal('dpb-aside') === 'right' ? 'ขวา' : '-'),
            "รูปแบบขอบ":          getVal('dpb-edge') === 'rounded' ? 'มุมมน' : 'มุมเหลี่ยม',
            "ทริมเมอร์_ไม้แท้":   getVal('dpb-solid-trim') === 'trim' ? 'Yes' : 'No'
        };

        const corners = {
            "Rect_บนซ้าย_mm":   getNum('r_rect_tl'),  "Rect_บนขวา_mm":   getNum('r_rect_tr'),
            "Rect_ล่างซ้าย_mm": getNum('r_rect_bl'),  "Rect_ล่างขวา_mm": getNum('r_rect_br'),
            "L_บนซ้าย_mm":      getNum('ld_r_tl'),    "L_บนขวา_mm":      getNum('ld_r_tr'),
            "L_ด้านใน_mm":      getNum('dpb-rInner'),
            "L_ล่างซ้าย_mm":    getNum('ld_r_armbl'), "L_ล่างขวา_mm":    getNum('ld_r_armbr'),
            "L_จุดหัก_Step_mm": getNum('ld_r_step')
        };

        const options_list = [];
        const globalState = window.state || {};
        if (!globalState.optConfig) {
        }
        if (globalState.optConfig) {
            const mapVert = (v) => { v=String(v||'').toLowerCase(); return (v==='top'||v==='บน')?'บน':(v==='bottom'||v==='ล่าง')?'ล่าง':(v==='center'||v==='กลาง')?'กลาง':v||'-'; };
            const mapHorz = (v) => { v=String(v||'').toLowerCase(); return (v==='left'||v==='ซ้าย')?'ซ้าย':(v==='right'||v==='ขวา')?'ขวา':(v==='center'||v==='กลาง')?'กลาง':v||'-'; };
            Object.keys(globalState.optConfig).forEach(key => {
                const configs = globalState.optConfig[key];
                if (Array.isArray(configs)) {
                    configs.forEach(cfg => {
                        let p = cfg.pos;
                        if (p==='left') p='ซ้าย';
                        else if (p==='right') p='ขวา';
                        else if (p==='main') p='หลัก';
                        options_list.push({
                            "ชื่อ":           key,
                            "ตำแหน่ง_Zone":   p||'-',
                            "จัดวาง_แนวตั้ง": mapVert(cfg.from),
                            "จัดวาง_แนวนอน":  mapHorz(cfg.place),
                            "ระยะX":          cfg.offsetX||0,
                            "ระยะY":          cfg.offsetY||0,
                            "หมุน":           cfg.rotate?'Yes':'No',
                            "Variant":        cfg.variant||'-'
                        });
                    });
                }
            });
        }

        const mainCanvas    = getEl('dpb-canvas');
        const legCheckbox   = getEl('dpb-show-legs');
        const legSwitchWrap = document.querySelector('.dpb-switch-legs');

        let needRestore = false;
        let overlayImg  = null;

        const isGapChanged        = checkLegGapModified();
        const hasLegCollision     = checkLegCollisionWarning();
        const isHidden            = legCheckbox && !legCheckbox.checked;
        const shouldForceShowLegs = isHidden && (isGapChanged || hasLegCollision);

        if (mainCanvas && legCheckbox && shouldForceShowLegs) {
            needRestore = true;
            try {
                const currentImgData = mainCanvas.toDataURL();
                overlayImg = document.createElement('img');
                overlayImg.src = currentImgData;
                const canvasRect = mainCanvas.getBoundingClientRect();
                const parent     = mainCanvas.parentNode;
                const parentRect = parent.getBoundingClientRect();
                const relTop     = canvasRect.top  - parentRect.top;
                const relLeft    = canvasRect.left - parentRect.left;
                overlayImg.style.position      = 'absolute';
                overlayImg.style.top           = relTop + 'px';
                overlayImg.style.left          = relLeft + 'px';
                overlayImg.style.width         = canvasRect.width + 'px';
                overlayImg.style.height        = canvasRect.height + 'px';
                overlayImg.style.zIndex        = '9999';
                overlayImg.style.pointerEvents = 'none';
                const parentStyle = window.getComputedStyle(parent);
                if (parentStyle.position === 'static') parent.style.position = 'relative';
                parent.appendChild(overlayImg);
            } catch(e) { console.warn("Overlay Fail", e); }

            if (legSwitchWrap) legSwitchWrap.classList.add('frozen');
            legCheckbox.checked = true;
            const legChangeEvent = new Event('change', { bubbles: true });
            legCheckbox.dispatchEvent(legChangeEvent);

            await new Promise(r => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        setTimeout(r, 150);
                    });
                });
            });
        }

        let imageSnapshot = '';
        if (mainCanvas) {
            try {
                const targetWidth  = 600;
                const scale        = targetWidth / mainCanvas.width;
                const targetHeight = mainCanvas.height * scale;
                const tmpCanvas    = document.createElement('canvas');
                tmpCanvas.width    = targetWidth;
                tmpCanvas.height   = targetHeight;
                const ctx          = tmpCanvas.getContext('2d');
                ctx.drawImage(mainCanvas, 0, 0, targetWidth, targetHeight);
                imageSnapshot = tmpCanvas.toDataURL('image/jpeg', 0.7);
            } catch (imgErr) {
                imageSnapshot = 'Error capturing image';
            }
        }

        if (needRestore) {
            if (legCheckbox) {
                legCheckbox.checked = false;
                const event = new Event('change', { bubbles: true });
                legCheckbox.dispatchEvent(event);
            }
            await new Promise(r => setTimeout(r, 100));
            if (legSwitchWrap) legSwitchWrap.classList.remove('frozen');
            if (overlayImg) overlayImg.remove();
        }

        let warningCode    = null;
        let warningMessage = '';

        if (hasLegCollision) {
            warningCode    = 'collision';
            warningMessage = 'ตำแหน่งของรู Option ตรงกับขาโต๊ะ';
        } else if (isGapChanged) {
            warningCode    = 'gap_changed';
            warningMessage = 'มีการปรับเปลี่ยนตำแหน่งของขา';
        }
        if (shouldForceShowLegs) {
            warningMessage += ' (System Forced Legs Show)';
        }

        // [FIX B] ดึง User Info จาก PHP
        let log_user_status = 'Guest';
        let log_username    = 'guest';

        if (typeof window.DSLOG_User_Info !== 'undefined' && window.DSLOG_User_Info.is_logged) {
            log_user_status = window.DSLOG_User_Info.user_status;
            log_username    = window.DSLOG_User_Info.user_login;
        } else if (typeof window.ds_auth_vars !== 'undefined' && window.ds_auth_vars.is_logged) {
            log_user_status = 'Logged In';
            const u = window.ds_auth_vars.current_user;
            if (u) log_username = u.user_login || u.display_name || 'unknown_user';
        } else if (document.body.classList.contains('logged-in')) {
            log_user_status = 'Logged In';
            log_username    = 'wp-user';
        }

        const _privacyCb       = document.getElementById('dpbPrivacyCheckbox');
        const _privacyAccepted = _privacyCb ? _privacyCb.checked : false;

        const payload = {
            "User_Status":          log_user_status,
            "Account_Name":         log_username,
            "ข้อมูลลูกค้า":          customer,
            "สเปคโต๊ะ":              desk_spec,
            "ระยะห่างขาโต๊ะ":        leg_spacing,
            "รายละเอียดมุมโต๊ะ":     corners,
            "รายการ_Options":        options_list,
            "จำนวน_Options":         options_list.length,
            "Warning_Code":          warningCode,
            "Note_System":           warningMessage,
            "รูปภาพ_Snapshot":        imageSnapshot,
            "traffic_source":        window.DSLOG_Traffic_Info.source,
            "device_type":           window.DSLOG_Traffic_Info.device,
            "Privacy_Consent":       _privacyAccepted ? "Accepted" : "Not Accepted",
            "Privacy_Consent_Time":  _privacyAccepted ? new Date().toISOString() : null
        };

        // [FIX MAIN] ใช้ FormData — ส่ง action ใน body เพื่อให้ WordPress route ถูกต้อง
        const finalPayload = Object.assign({}, payload);
        window.DSLOG_pendingPayload = finalPayload;

        const fd = new FormData();
        fd.append('action', 'DSLOG_Deskspace_save_log');
        fd.append('nonce',  window.DSLOG_V7_Config.nonce);
        fd.append('data',   JSON.stringify(finalPayload));

        const saveRes  = await fetch(window.DSLOG_V7_Config.url, {
            method: 'POST',
            body:   fd
        });
        const saveJson = await saveRes.json();

        if (saveJson.success) {
            window.DSLOG_pendingPayload = null;
        } else {
        }

    } catch (err) {
        // ← try...catch ปิดถูกต้อง ครอบทั้งฟังก์ชัน
        console.error("DSLOG Error:", err);
    }
}
// ← ปิดฟังก์ชัน DSLOG_Deskspace_collectAndSave


// ─────────────────────────────────────────────────────────────────────────────
// Click Listener
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('button, a, .dpb-btn-ghost, #dpb-download');
    if (!btn) return;
	
	  // [FIX] เพิ่ม blacklist — ปุ่มที่มี log ของตัวเองอยู่แล้ว ไม่ต้อง trigger DSLOG ซ้ำ
    const blacklist = ['dpbSubmitBtn'];
    if (blacklist.includes(btn.id)) return;

    const btnText = btn.innerText ? btn.innerText.toLowerCase() : '';
    const isTarget =
        btn.id === 'dpb-download'        ||
        btn.id === 'dpb-footer-download' ||
        btn.id === 'dpb-popup-save-btn'  ||
        btnText.includes('บันทึก')        ||
        btnText.includes('download');

    if (isTarget) {
        if (window.DSLOG_saveTimer) {
            clearTimeout(window.DSLOG_saveTimer);
        }
        window.DSLOG_saveTimer = setTimeout(DSLOG_Deskspace_collectAndSave, 1000);
    }
});


// ─────────────────────────────────────────────────────────────────────────────
// Pagehide — ส่ง beacon ถ้ายังมี pending payload
// ─────────────────────────────────────────────────────────────────────────────
window.addEventListener('pagehide', function() {
    if (window.DSLOG_pendingPayload) {
        const params = new URLSearchParams();
        params.append('action', 'DSLOG_Deskspace_save_log');
        params.append('nonce',  window.DSLOG_V7_Config.nonce);
        params.append('data',   JSON.stringify(window.DSLOG_pendingPayload));

        navigator.sendBeacon(
            window.DSLOG_V7_Config.url,
            new Blob([params.toString()], {
                type: 'application/x-www-form-urlencoded'
            })
        );
        window.DSLOG_pendingPayload = null;
    }
});
</script>



<script>
// =========================================
// STATE
// =========================================
let dpbCurrentIntent = null; // 'quote' | 'inquiry'

// =========================================
// OPEN / CLOSE
// =========================================
function dpbOpenModal() {
  const modal = document.getElementById('dpbModal');
  if (!modal) return;
  modal.style.display = 'flex';
  requestAnimationFrame(() => {
    requestAnimationFrame(() => modal.classList.add('visible'));
  });

  // Reset to step 1
  dpbShowView('intent');

  // Load canvas preview
  const canvas = document.querySelector('canvas');
  const img = document.getElementById('dpb_preview_img');
  const placeholder = document.getElementById('dpb_preview_placeholder');
  if (canvas && img) {
    try {
      img.src = canvas.toDataURL('image/png');
      img.style.display = 'block';
      if (placeholder) placeholder.style.display = 'none';
    } catch(e) { console.warn('Canvas preview error:', e); }
  }
}

function dpbCloseModal() {
  const modal = document.getElementById('dpbModal');
  if (!modal) return;
  modal.classList.remove('visible');
  setTimeout(() => { modal.style.display = 'none'; }, 350);
}

// Close on overlay click
document.getElementById('dpbModal').addEventListener('click', function(e) {
  if (e.target === this) dpbCloseModal();
});

// =========================================
// NAVIGATION
// =========================================
function dpbSelectIntent(intent) {
  dpbCurrentIntent = intent;
  dpbShowView(intent === 'quote' ? 'quoteForm' : 'inquiryForm');
}

function dpbGoBack() {
  dpbCurrentIntent = null;
  dpbShowView('intent');
}

function dpbShowView(view) {
  const intentView   = document.getElementById('dpbIntentView');
  const quoteView    = document.getElementById('dpbQuoteView');
  const inquiryView  = document.getElementById('dpbInquiryView');
  const successView  = document.getElementById('dpbSuccessView');
  const privacyView  = document.getElementById('dpbPrivacyView');
  const footer       = document.getElementById('dpbFooter');
  const title        = document.getElementById('dpbModalTitle');
  const dot1         = document.getElementById('dpbDot1');
  const dot2         = document.getElementById('dpbDot2');
  const progress     = document.getElementById('dpbProgress');
  const scrollArea   = document.querySelector('.dpb-modal-scroll');

  // Hide all
  [intentView, quoteView, inquiryView].forEach(v => { if(v) v.style.display = 'none'; });
  successView.style.display  = 'none';
  privacyView.style.display  = 'none';
  footer.style.display       = 'none';
  progress.style.display     = 'flex';
  if (scrollArea) scrollArea.style.overflow = 'auto';

  if (view === 'intent') {
    intentView.style.display = 'block';
    title.textContent = 'ติดต่อเรา';
    dot1.classList.add('active'); dot2.classList.remove('active');

  } else if (view === 'quoteForm') {
    quoteView.style.display = 'block';
    footer.style.display = 'flex';
    title.textContent = 'ขอใบเสนอราคา';
    dot1.classList.remove('active'); dot2.classList.add('active');
    // Reset checkbox state
    dpbResetPrivacyCheckbox();

  } else if (view === 'inquiryForm') {
    inquiryView.style.display = 'block';
    footer.style.display = 'flex';
    title.textContent = 'สอบถามข้อมูลเพิ่มเติม';
    dot1.classList.remove('active'); dot2.classList.add('active');
    // Reset checkbox state
    dpbResetPrivacyCheckbox();

  } else if (view === 'success') {
    successView.style.display = 'block';
    progress.style.display = 'none';

  } else if (view === 'privacy') {
    privacyView.style.display = 'flex';
    progress.style.display = 'none';
    if (scrollArea) scrollArea.style.overflow = 'hidden';
  }
}

// =========================================
// PRIVACY VIEW FUNCTIONS
// =========================================
function dpbOpenPrivacyView() {
  dpbShowView('privacy');
}

function dpbClosePrivacyView() {
  // กลับไป view ที่เหมาะสมตาม intent ปัจจุบัน
  if (dpbCurrentIntent === 'quote') {
    dpbShowView('quoteForm');
  } else if (dpbCurrentIntent === 'inquiry') {
    dpbShowView('inquiryForm');
  } else {
    dpbShowView('intent');
  }
}

function dpbResetPrivacyCheckbox() {
  const checkbox = document.getElementById('dpbPrivacyCheckbox');
  const submitBtn = document.getElementById('dpbSubmitBtn');
  if (checkbox) checkbox.checked = false;
  if (submitBtn) submitBtn.disabled = true;
}

// =========================================
// PRIVACY CHECKBOX — toggle submit button
// =========================================
document.addEventListener('DOMContentLoaded', function() {
  const checkbox  = document.getElementById('dpbPrivacyCheckbox');
  const submitBtn = document.getElementById('dpbSubmitBtn');
  if (checkbox && submitBtn) {
    checkbox.addEventListener('change', function() {
      submitBtn.disabled = !this.checked;
    });
  }
});


// =========================================
// VALIDATION HELPER
// =========================================
function dpbValidateField(id) {
  const el = document.getElementById(id);
  if (!el) return true;
  const val = el.value.trim();
  if (!val) {
    el.classList.add('dpb-error');
    return false;
  }
  el.classList.remove('dpb-error');
  return true;
}
function dpbClearErrors(ids) {
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('dpb-error');
  });
}


// =========================================
// SUBMIT
// =========================================
function dpbSubmitForm() {
  const btn = document.getElementById('dpbSubmitBtn');

  if (dpbCurrentIntent === 'quote') {
    dpbClearErrors(['dpb_q_name','dpb_q_email','dpb_q_tel']);
    const valid = dpbValidateField('dpb_q_name') &
                  dpbValidateField('dpb_q_email') &
                  dpbValidateField('dpb_q_tel');
    if (!valid) return;
    dpbDoSubmit({
      name:    document.getElementById('dpb_q_name').value.trim(),
      email:   document.getElementById('dpb_q_email').value.trim(),
      tel:     document.getElementById('dpb_q_tel').value.trim(),
      line_id: document.getElementById('dpb_q_line').value.trim(),
    }, document.getElementById('dpb_q_note').value.trim(), btn); // <-- [แก้ไขจุดนี้] ดึงค่าจาก dpb_q_note ส่งเป็นพารามิเตอร์ที่ 2 (question)

  } else if (dpbCurrentIntent === 'inquiry') {
    dpbClearErrors(['dpb_i_name','dpb_i_email','dpb_i_tel','dpb_i_question']);
    const valid = dpbValidateField('dpb_i_name') &
                  dpbValidateField('dpb_i_email') &
                  dpbValidateField('dpb_i_tel') &
                  dpbValidateField('dpb_i_question');
    if (!valid) return;
    dpbDoSubmit({
      name:    document.getElementById('dpb_i_name').value.trim(),
      email:   document.getElementById('dpb_i_email').value.trim(),
      tel:     document.getElementById('dpb_i_tel').value.trim(),
      line_id: document.getElementById('dpb_i_line').value.trim(),
    }, document.getElementById('dpb_i_question').value.trim(), btn);
  }
}

function dpbDoSubmit(contactInfo, question, btn) {
  btn.disabled = true;
  btn.classList.add('loading');

  // --- Build summary (same logic as original, keep your existing getVal/getTxt helpers) ---
  // This section mirrors your original summary_data builder
  const getEl  = id => document.getElementById(id);
  const getVal = id => { const el = getEl(id); return (el && el.value) ? el.value : ''; };
  const getNum = id => { const val = getVal(id); return (val===''||val===null) ? '-' : val; };
  const getTxt = id => { const el = getEl(id); return (el && el.selectedOptions && el.selectedOptions[0]) ? el.selectedOptions[0].text.trim() : ''; };

  const deskTypeVal = getVal('dpb-type');
  const deskTypeTxt = getTxt('dpb-type') || deskTypeVal;
  const isLShape = /(L2|L3|L-Shape)/i.test(deskTypeTxt) || /(L2|L3|L-Shape)/i.test(deskTypeVal);

  let desk_spec = {
    "รุ่นโต๊ะ": deskTypeTxt || '-',
    "สีท็อป": getTxt('dpb-top-color') || '-',
    "ขาโต๊ะ": getTxt('dpb-legs') || '-',
    "รูปแบบขอบ": (getVal('dpb-edge') === 'rounded') ? 'มุมมน' : 'มุมเหลี่ยม'
  };

  if (isLShape) {
    desk_spec["ขนาด Main (กว้างxยาว)"] = `${getNum('dpb-mw')} x ${getNum('dpb-ml')} cm`;
    desk_spec["ขนาด L-Side (กว้างxยาว)"] = `${getNum('dpb-aw')} x ${getNum('dpb-al')} cm`;
    const asideVal = getVal('dpb-aside');
    desk_spec["ฝั่งตัว L"] = (asideVal==='left') ? 'อยู่ทางซ้าย' : (asideVal==='right' ? 'อยู่ทางขวา' : '-');
  } else {
    desk_spec["ขนาดโต๊ะ (กว้างxยาว)"] = `${getNum('dpb-mw')} x ${getNum('dpb-ml')} cm`;
  }

  const options_list = [];
  const globalState = (typeof state !== 'undefined') ? state : {};
  if (globalState.optConfig) {
    Object.keys(globalState.optConfig).forEach(key => {
      const configs = globalState.optConfig[key];
      if (Array.isArray(configs) && configs.length > 0) {
        const nameCounts = {};
        configs.forEach(item => {
          let displayName = item.nameth || item.name || key;
          let vName = item.variantName || item.variant || item.variant_name || '';
          if (vName && vName !== '-' && vName !== 'Default') displayName += ` (${vName})`;
          nameCounts[displayName] = (nameCounts[displayName] || 0) + 1;
        });
        Object.keys(nameCounts).forEach(n => options_list.push({ "รายการ": n, "จำนวน": nameCounts[n] + ' ชิ้น' }));
      }
    });
  }

  // Canvas image
  const canvas = document.querySelector('canvas');
  let mimeType = 'image/jpeg', quality = 0.9;
  const activeBtn = document.querySelector('#dpb-bg button.active') || document.querySelector('#dpb-bg button.selected');
  if (activeBtn && activeBtn.getAttribute('data-value') === 'rgba(0,0,0,0)') { mimeType = 'image/png'; quality = 1.0; }
  const imgData = canvas ? canvas.toDataURL(mimeType, quality) : '';

  const ajaxEndpoint = (typeof dpb_ajax !== 'undefined') ? dpb_ajax.url : '';
  const nonce        = (typeof dpb_ajax !== 'undefined') ? dpb_ajax.nonce : '';

const privacyCheckbox = document.getElementById('dpbPrivacyCheckbox');
  const privacyAccepted = privacyCheckbox ? privacyCheckbox.checked : false;

  const postData = {
    action: 'dpb_send_proposal_v5',
    nonce: nonce,
    intent: dpbCurrentIntent,
    contact_info: contactInfo,
    question: question || '',
    image_data: imgData,
    summary_data: JSON.stringify({ "สเปคโต๊ะ": desk_spec, "รายการ_Options": options_list }),
    privacy_accepted: privacyAccepted ? '1' : '0'
  };

  // For demo purposes (no WordPress):
  if (!ajaxEndpoint) {
    setTimeout(() => {
      btn.disabled = false;
      btn.classList.remove('loading');
      dpbShowSuccess();
    }, 1500);
    return;
  }

  jQuery.post(ajaxEndpoint, postData, function(response) {
    if (response.success) {
      dpbShowSuccess();
      
      // [แก้ไขจุดนี้] สั่งให้ระบบเก็บ Log (ตัว Snapshot เต็ม) ทันทีที่อีเมลถูกส่งสำเร็จ
      if (typeof DSLOG_Deskspace_collectAndSave === 'function') {
          DSLOG_Deskspace_collectAndSave();
      }

    } else {
      alert('เกิดข้อผิดพลาด: ' + (response.data || 'Unknown error'));
    }
  }).fail(function(xhr, status, error) {
    alert('Error: ' + error);
  }).always(function() {
    btn.disabled = false;
    btn.classList.remove('loading');
  });
}

function dpbShowSuccess() {
  const msgEl = document.getElementById('dpbSuccessMsg');
  if (dpbCurrentIntent === 'quote') {
    msgEl.innerHTML = 'ทีมงานได้รับคำขอใบเสนอราคาของคุณแล้ว<br>จะประเมินราคาและติดต่อกลับทาง <strong>Line หรืออีเมล</strong> โดยเร็วที่สุดค่ะ';
  } else {
    msgEl.innerHTML = 'ทีมงานได้รับข้อความของคุณแล้ว<br>เราจะรีบติดต่อกลับเพื่อดูแลและให้คำแนะนำ ภายใน 24 ชั่วโมงทำการค่ะ';
  }
  dpbShowView('success');
}

// Mobile button
document.addEventListener('DOMContentLoaded', function() {
  const mobileBtn = document.getElementById('dpb-mobile-quote-btn');
  if (mobileBtn) {
    mobileBtn.addEventListener('touchstart', function(e) {
      e.preventDefault();
      dpbOpenModal();
    }, { passive: false });
    mobileBtn.addEventListener('click', function(e) {
      e.preventDefault();
      dpbOpenModal();
    });
  }
});
</script>

<style>
    /* --- 0. Global Variables & Modern High-End Theme (Scoped with ai-) --- */
    :root {
        --ai-dpb-gold: #b69652;
        --ai-dpb-gold-hover: #9e803e;
        --ai-dpb-dark: #1a1a1a;
        --ai-dpb-gray-text: #4a4a4a;
        --ai-dpb-bg-glass: rgba(255, 255, 255, 0.95);
        --ai-dpb-bg-panel: #f8f9fb;
        --ai-dpb-border: rgba(0, 0, 0, 0.08);
        --ai-dpb-shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        --ai-dpb-radius-xl: 24px;
        --ai-dpb-radius-lg: 16px;
        --ai-dpb-radius-sm: 10px;
        --ai-dpb-anim: cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    /* --- Floating Button (SVG Only) --- */
    #ai-dpb-ai-btn {
        position: fixed;
        bottom: 40px;
        left: 40px;
        width: 60px;
        height: 60px;
        background: #fff;
        border-radius: 50%;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        cursor: pointer;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--ai-dpb-gold);
        transition: all 0.4s var(--ai-dpb-anim);
        border: 1px solid rgba(182, 150, 82, 0.2);
    }
    #ai-dpb-ai-btn:hover {
        transform: translateY(-5px) scale(1.05);
        background: var(--ai-dpb-gold);
        color: #fff;
        box-shadow: 0 15px 35px rgba(182, 150, 82, 0.4);
    }

    /* --- Modal Overlay --- */
    #ai-dpb-modal-v2 {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(10, 10, 10, 0.6);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        z-index: 999999999;
        justify-content: center;
        align-items: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    #ai-dpb-modal-v2.ai-dpb-show { display: flex !important; opacity: 1; }

    /* --- Main Container (Split Layout) --- */
    .ai-dpb-main-card {
        background: #fff;
        max-width: 85vw;
        max-height: 90vh;
        border-radius: var(--ai-dpb-radius-xl);
        box-shadow: var(--ai-dpb-shadow-xl);
        display: flex;
        overflow: hidden;
        transform: scale(0.95);
        transition: transform 0.4s var(--ai-dpb-anim);
        position: relative;
    }
    #ai-dpb-modal-v2.ai-dpb-show .ai-dpb-main-card { transform: scale(1); }

    /* --- Close Button --- */
    .ai-dpb-modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 100;
        color: #666;
        transition: all 0.2s;
    }
    .ai-dpb-modal-close:hover { background: #b69652; color: #fff; }

    /* --- LEFT PANEL: Controls --- */
    .ai-dpb-panel-left {
        width: 38%;
        background: var(--ai-dpb-bg-panel);
        border-right: 1px solid var(--ai-dpb-border);
        padding: 30px;
        display: flex;
        flex-direction: column;
    }

    .ai-dpb-header-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--ai-dpb-dark);
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ai-dpb-section-label {
        font-size: 13px;
        font-weight: 600;
        color: #888;
        text-transform: uppercase;
        margin-bottom: 12px;
        letter-spacing: 0.5px;
    }

    /* Style Grid */
    .ai-dpb-style-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 25px;
    }

    .ai-dpb-style-card {
        background: #fff;
        border: 2px solid transparent;
        border-radius: var(--ai-dpb-radius-lg);
        padding: 10px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    }
    .ai-dpb-style-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.06); }
    .ai-dpb-style-card.ai-active { border-color: var(--ai-dpb-gold); background: rgba(182, 150, 82, 0.05); }

    .ai-dpb-style-img {
        width: 50px;
        height: 50px;
        border-radius: 8px;
        background: #eee;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #aaa;
    }
    .ai-dpb-style-name { font-size: 12px; font-weight: 600; color: var(--ai-dpb-dark); }

    /* Element List */
    .ai-dpb-element-list {
        display: flex;
        flex-direction: row;
        gap: 12px;
        margin-bottom: 5px;
        flex-wrap: wrap;
        padding-bottom: 5px;
    }

    .ai-dpb-element-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 8px 10px;
        background: #fff;
        border-radius: var(--ai-dpb-radius-lg);
        border: 1px solid rgba(0,0,0,0.04);
        cursor: pointer;
        transition: all 0.2s;
        width: 110px; 
        flex-shrink: 0; 
        text-align: center;
    }

    .ai-dpb-element-item span {
        font-size: 12px;
        line-height: 1.3;
    }

    .ai-dpb-element-item:hover { border-color: var(--ai-dpb-gold); }
    .ai-dpb-element-item.ai-active { background: var(--ai-dpb-gold); color: #fff; }

    /* Action Area */
    .ai-dpb-action-area {
        margin-top: auto;
        padding-top: 20px;
        border-top: 1px solid var(--ai-dpb-border);
    }

    .ai-dpb-btn-gen {
        width: 100%;
        padding: 14px;
        background: var(--ai-dpb-gold);
        color: #fff;
        border: none;
        border-radius: 50px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(182, 150, 82, 0.3);
        transition: all 0.3s;
    }
    .ai-dpb-btn-gen:hover { background: var(--ai-dpb-gold-hover); transform: translateY(-2px); }
    .ai-dpb-btn-gen:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }

    /* History Section */
    .ai-dpb-history-section {
        border-top: 1px solid var(--ai-dpb-border);
    }
    .ai-dpb-history-grid {
        display: flex;
        flex-wrap: wrap;
        height: 150px;
        overflow-y: scroll;
        gap: 8px;
        margin-top: 10px;
    }
    .ai-dpb-history-card {
        aspect-ratio: 1 / 1;
        border-radius: var(--ai-dpb-radius-sm);
        background: #f0f0f0;
        cursor: pointer;
        max-width: 90px;
        border: 2px solid transparent;
        transition: all 0.2s;
        position: relative;
    }
    .ai-dpb-history-card:hover { border-color: var(--ai-dpb-gold); transform: scale(1.05); }
    .ai-dpb-history-card img { width: 100%; height: 100%; object-fit: cover; }

    /* --- RIGHT PANEL: Display --- */
    .ai-dpb-panel-right {
        width: 62%;
        padding: 30px;
        background: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .ai-dpb-display-box {
        width: 100%;
        height: 100%;
        border-radius: var(--ai-dpb-radius-lg);
        overflow: hidden;
        position: relative;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ai-dpb-display-img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        transition: filter 0.3s ease;
    }

    /* Blur Effect Class */
    .ai-dpb-blur-loading {
        filter: blur(8px);
    }

    /* Loading Overlay */
    .ai-dpb-loading-overlay {
        position: absolute;
        inset: 0;
        background: rgba(255,255,255,0.4);
        z-index: 10;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s;
        overflow: hidden;
    }
    .ai-dpb-loading-overlay.ai-active { opacity: 1; pointer-events: auto; }

    /* Glimmer Effect */
    .ai-dpb-loading-overlay.ai-active::after {
        content: "";
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        background: linear-gradient(
            to right,
            rgba(255, 255, 255, 0) 0%,
            rgba(255, 255, 255, 0.8) 50%,
            rgba(255, 255, 255, 0) 100%
        );
        transform: skewX(-20deg) translateX(-150%);
        animation: ai-glimmer 2s infinite;
        pointer-events: none;
        z-index: 1;
    }
    @keyframes ai-glimmer {
        0% { transform: skewX(-20deg) translateX(-150%); }
        100% { transform: skewX(-20deg) translateX(150%); }
    }

    .ai-dpb-scan-line {
        width: 100%;
        height: 4px;
        background: var(--ai-dpb-gold);
        box-shadow: 0 0 20px var(--ai-dpb-gold);
        position: absolute;
        top: 0;
        animation: ai-scan 2s linear infinite;
        z-index: 5;
    }
    @keyframes ai-scan { 0% {top:0} 50% {top:100%} 100% {top:0} }

    /* Progress Bar */
    .ai-dpb-progress-container { 
        width: 300px; 
        margin-top: 20px; 
        text-align: center; 
        z-index: 10;
        position: relative;
    }
    .ai-dpb-progress-bg { height: 6px; background: #eee; border-radius: 10px; overflow: hidden; margin: 10px 0; }
    .ai-dpb-progress-fill { height: 100%; width: 0%; background: var(--ai-dpb-gold); transition: width 0.3s; }
    .ai-dpb-status-text { 
        font-size: 14px; 
        color: var(--ai-dpb-gold); 
        font-weight: 600; 
        background: rgba(255,255,255,0.8); 
        padding: 4px 12px;
        border-radius: 20px;
    }

    /* Error Message Container */
    .ai-dpb-error-container {
        width: 100%;
        min-height: 24px;
        margin-top: 15px;
        text-align: center;
        font-size: 14px;
        font-weight: 500;
        color: #ef4444;
        opacity: 0;
        transition: opacity 0.3s;
    }
    .ai-dpb-error-container.ai-visible { opacity: 1; }

    /* Result Controls */
    .ai-dpb-result-controls {
        position: absolute;
        bottom: 30px;
        display: flex;
        gap: 10px;
        opacity: 0;
        transform: translateY(10px);
        transition: all 0.3s;
    }
    .ai-dpb-display-box.ai-finished ~ .ai-dpb-result-controls { opacity: 1; transform: translateY(0); }

    .ai-dpb-btn-secondary {
        padding: 10px 20px;
        border-radius: 50px;
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-size: 14px;
        cursor: pointer;
        display: flex; align-items: center; gap: 6px;
        transition: all 0.2s;
        text-decoration: none;
    }
    .ai-dpb-btn-secondary:hover { border-color: var(--ai-dpb-dark); color: var(--ai-dpb-dark); }
    
    .ai-dpb-btn-gen-download {
        padding: 14px;
        background: var(--ai-dpb-gold);
        color: #fff;
        border: none;
        border-radius: 50px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(182, 150, 82, 0.3);
        transition: all 0.3s;
    }
    .ai-dpb-btn-gen-download:hover { background: var(--ai-dpb-gold-hover); transform: translateY(-2px); }
    .ai-dpb-btn-gen-download:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Credit Badge */
    .ai-dpb-credit-badge {
        position: absolute;
        z-index: 11;
        top: 20px;
        left: 20px;
        background: rgba(255,255,255,0.9);
        backdrop-filter: blur(5px);
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        color: #666;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        display: flex; align-items: center; gap: 5px;
    }
    .ai-dpb-credit-val { font-weight: 600; color: var(--ai-dpb-gold); }

    /* Responsive */
    @media (max-width: 850px) {
        .ai-dpb-main-card { flex-direction: column-reverse; width: 95%; height: 90vh; overflow-y: auto; }
        .ai-dpb-panel-left { width: 100%; padding: 20px; flex-shrink: 0; }
        .ai-dpb-panel-right { width: 100%; height: 350px; flex-shrink: 0; }
    }
</style>

<script>
(function() {
    // --- Configuration ---
    const CONFIG = {
        canShow: <?php echo $canShow3D_PHP ? 'true' : 'false'; ?>,
        webhookUrl: 'https://horrifically-interlinear-nola.ngrok-free.dev/webhook-test/generate-desk', 
        user: '<?php echo wp_get_current_user()->user_login ?: "guest"; ?>',
        chairImage: 'https://www.deskspace.in.th/wp-content/uploads/2026/01/Nebula_Back_ISO3.png'
    };

    // --- State Management ---
    let state = {
        style: 'minimal',
        element: [],
        isLoading: false,
        timer: null,
        credits: '-',
        history: [] 
    };

    // --- SVG Icons ---
    const ICONS = {
        magic: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>`,
        minimal: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>`,
        nature: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"></path></svg>`,
        exec: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>`,
        gamer: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="6" width="20" height="12" rx="2"></rect><path d="M6 12h4m-2-2v4m9-2h.01"></path></svg>`,
        check: `<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
        download: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`,
        refresh: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`
    };

    // --- Initialization ---
    function init() {
        if (!CONFIG.canShow) return;
        if (document.getElementById('ai-dpb-ai-btn')) return;

        const btn = document.createElement('div');
        btn.id = 'ai-dpb-ai-btn';
        btn.innerHTML = ICONS.magic;
        btn.onclick = openModal;
        document.body.appendChild(btn);

        createModalHTML();
    }

    function createModalHTML() {
        // [MODIFIED] Added ai- prefix to all classes and IDs
        const html = `
        <div id="ai-dpb-modal-v2">
            <div class="ai-dpb-main-card" onclick="event.stopPropagation()">
                <div class="ai-dpb-modal-close" onclick="closeModal()">✕</div>

                <div class="ai-dpb-panel-left">
                    <div class="ai-dpb-header-title">
                        ${ICONS.magic} DeskSpace AI
                    </div>

                    <div class="ai-dpb-section-label">1. เลือกสไตล์ห้อง (Background)</div>
                    <div class="ai-dpb-style-grid">
                        <div class="ai-dpb-style-card ai-active" onclick="selectStyle('minimal', this)">
                            <div class="ai-dpb-style-img">${ICONS.minimal}</div>
                            <div class="ai-dpb-style-name">Minimal</div>
                        </div>
                        <div class="ai-dpb-style-card" onclick="selectStyle('nature', this)">
                            <div class="ai-dpb-style-img">${ICONS.nature}</div>
                            <div class="ai-dpb-style-name">Nature</div>
                        </div>
                        <div class="ai-dpb-style-card" onclick="selectStyle('executive', this)">
                            <div class="ai-dpb-style-img">${ICONS.exec}</div>
                            <div class="ai-dpb-style-name">Executive</div>
                        </div>
                        <div class="ai-dpb-style-card" onclick="selectStyle('gamer', this)">
                            <div class="ai-dpb-style-img">${ICONS.gamer}</div>
                            <div class="ai-dpb-style-name">Gamer</div>
                        </div>
                    </div>

                    <div class="ai-dpb-section-label">2. เพิ่มอุปกรณ์</div>
                    <div class="ai-dpb-element-list">
                        <div class="ai-dpb-element-item" onclick="selectElement('nebula', this)">
                            <span>Chair</span>
                        </div>
                        <div class="ai-dpb-element-item" onclick="selectElement('pc', this)">
                            <span>Computer Set</span>
                        </div>
                        <div class="ai-dpb-element-item" onclick="selectElement('laptop', this)">
                            <span>Laptop Set</span>
                        </div>
                    </div>

                    <div class="ai-dpb-history-section" id="ai-history-section" style="display:none;">
                        <div class="ai-dpb-section-label">History</div>
                        <div class="ai-dpb-history-grid" id="ai-history-grid">
                            </div>
                    </div>
                    
                    <div class="ai-dpb-action-area">
                        <button id="ai-btn-generate" class="ai-dpb-btn-gen" onclick="startGeneration()">
                            ${ICONS.magic} Generate Image
                        </button>
                    </div>
                    
                </div>

                <div class="ai-dpb-panel-right">
                    <div class="ai-dpb-credit-badge">Credits: <span id="ai-txt-credit" class="ai-dpb-credit-val">-</span></div>
                    
                    <div class="ai-dpb-display-box" id="ai-dpb-display-box">
                        <img id="ai-dpb-preview-img" class="ai-dpb-display-img" src="" alt="Preview">
                        <div class="ai-dpb-loading-overlay" id="ai-loading-overlay">
                            <div class="ai-dpb-scan-line"></div>
                            <div class="ai-dpb-progress-container">
                                <div class="ai-dpb-status-text" id="ai-status-text">Preparing...</div>
                                <div class="ai-dpb-progress-bg"><div class="ai-dpb-progress-fill" id="ai-progress-fill" ></div></div>
                            </div>
                        </div>
                    </div>

                    <div id="ai-dpb-error-msg" class="ai-dpb-error-container"></div>

                    <div class="ai-dpb-result-controls">
                        <button class="ai-dpb-btn-secondary" onclick="resetUI()">
                            ${ICONS.refresh} ลองใหม่
                        </button>
                        <a id="ai-link-download" href="#" target="_blank" class="ai-dpb-btn-gen-download" style="padding: 10px 25px;">
                            ${ICONS.download} ดาวน์โหลด
                        </a>
                    </div>
                </div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', html);
        
        document.getElementById('ai-dpb-modal-v2').addEventListener('click', (e) => {
            if (e.target.id === 'ai-dpb-modal-v2') closeModal();
        });
    }

    window.selectStyle = function(styleName, el) {
        if(state.isLoading) return; 
        state.style = styleName;
        document.querySelectorAll('.ai-dpb-style-card').forEach(c => c.classList.remove('ai-active'));
        el.classList.add('ai-active');
    };

    window.selectElement = function(elemName, el) {
        if(state.isLoading) return; 

        // [LOGIC FIX] ตรวจสอบว่ามี element ที่ขัดแย้งกันหรือไม่ (PC <-> Laptop)
        const conflictMap = {
            'pc': 'laptop',
            'laptop': 'pc'
        };

        const index = state.element.indexOf(elemName);

        // กรณี: ต้องการ "ยกเลิก" ตัวที่เลือกอยู่แล้ว
        if (index > -1) {
            state.element.splice(index, 1);
            el.classList.remove('ai-active');
        } 
        // กรณี: ต้องการ "เลือก" ตัวใหม่
        else {
            const conflictName = conflictMap[elemName];

            // ถ้าตัวที่เลือกมีคู่ขัดแย้ง (เช่น เลือก pc แล้วคู่ขัดแย้งคือ laptop)
            if (conflictName) {
                const conflictIndex = state.element.indexOf(conflictName);
                
                // ถ้าคู่ขัดแย้งถูกเลือกอยู่ ให้เอาออกทั้งจาก State และ UI
                if (conflictIndex > -1) {
                    // 1. เอาออกจาก State
                    state.element.splice(conflictIndex, 1);

                    // 2. เอา class ai-active ออกจากปุ่มของคู่ขัดแย้ง
                    // ค้นหาปุ่มที่มี onclick ตรงกับชื่อคู่ขัดแย้ง
                    const allButtons = document.querySelectorAll('.ai-dpb-element-item');
                    allButtons.forEach(btn => {
                        const onclickVal = btn.getAttribute('onclick');
                        if (onclickVal && onclickVal.includes(`'${conflictName}'`)) {
                            btn.classList.remove('ai-active');
                        }
                    });
                }
            }

            // เพิ่มตัวใหม่เข้าไป
            state.element.push(elemName);
            el.classList.add('ai-active');
        }
    };

    // [MODIFIED] Helper to reset using ai- prefix
    window.openModal = function() {
        if(!document.getElementById('ai-dpb-modal-v2')) createModalHTML();
        
        renderHistory();
        resetToCanvasOriginal();
        
        const errDiv = document.getElementById('ai-dpb-error-msg');
        if(errDiv) {
            errDiv.innerText = '';
            errDiv.classList.remove('ai-visible');
        }

        if(!state.isLoading) {
             const btn = document.getElementById('ai-btn-generate');
             btn.disabled = false;
             btn.innerHTML = `${ICONS.magic} Generate Image`;
        }
        
        updateCredit();
        const modal = document.getElementById('ai-dpb-modal-v2');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('ai-dpb-show'), 10);
    };

    window.closeModal = function() {
        const modal = document.getElementById('ai-dpb-modal-v2');
        if(modal) {
            modal.classList.remove('ai-dpb-show');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }
    };

    function getResizedDataURL(canvas, targetWidth, targetHeight) {
        const tempCanvas = document.createElement('canvas');
        const ctx = tempCanvas.getContext('2d');
        tempCanvas.width = targetWidth;
        tempCanvas.height = targetHeight;
        
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        
        ctx.drawImage(canvas, 0, 0, targetWidth, targetHeight);
        
        return tempCanvas.toDataURL('image/png');
    }

    function resetToCanvasOriginal() {
        try {
            // [NOTE] dpb-canvas is EXTERNAL ID, DO NOT CHANGE
            const canvas = document.getElementById('dpb-canvas');
            if (canvas) {
                const dataUrl = getResizedDataURL(canvas, 1920, 1477);
                
                const img = document.getElementById('ai-dpb-preview-img');
                img.src = dataUrl;
                img.classList.remove('ai-dpb-blur-loading');
            }
        } catch(e) { console.warn("Canvas capture error", e); }
    }

    window.resetUI = function() {
        state.isLoading = false;
        clearInterval(state.timer);
        document.getElementById('ai-loading-overlay').classList.remove('ai-active');
        document.getElementById('ai-dpb-display-box').classList.remove('ai-finished');
        
        const errDiv = document.getElementById('ai-dpb-error-msg');
        errDiv.innerText = '';
        errDiv.classList.remove('ai-visible');

        const btnGen = document.getElementById('ai-btn-generate');
        btnGen.disabled = false;
        btnGen.innerHTML = `${ICONS.magic} Generate Image`;
        
        resetToCanvasOriginal();
    };

    // [NEW] History Functions (Updated IDs)
    function addToHistory(url) {
        state.history.unshift(url); 
        if(state.history.length > 6) state.history.pop(); 
        renderHistory();
    }

    function renderHistory() {
        const grid = document.getElementById('ai-history-grid');
        const section = document.getElementById('ai-history-section');
        
        if(!grid || !section) return;

        if(state.history.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        grid.innerHTML = state.history.map(url => `
            <div class="ai-dpb-history-card" onclick="viewHistoryImage('${url}')">
                <img src="${url}" loading="lazy">
            </div>
        `).join('');
    }

    window.viewHistoryImage = function(url) {
        if(state.isLoading) return;
        const img = document.getElementById('ai-dpb-preview-img');
        img.src = url;
        img.classList.remove('ai-dpb-blur-loading');
        
        document.getElementById('ai-dpb-display-box').classList.add('ai-finished');
        const dl = document.getElementById('ai-link-download');
        dl.href = url;
        dl.download = `DeskSpace-History-${Date.now()}.png`;
    };

   // --- Core Logic ---
    window.startGeneration = async function() {
        // [NOTE] External ID, DO NOT CHANGE
        const canvas = document.getElementById('dpb-canvas');
        if(!canvas) { alert('ไม่พบ Canvas 3D'); return; }

        const errDiv = document.getElementById('ai-dpb-error-msg');
        errDiv.innerText = '';
        errDiv.classList.remove('ai-visible');

        state.isLoading = true;
        document.getElementById('ai-btn-generate').disabled = true;
        
        const overlay = document.getElementById('ai-loading-overlay');
        overlay.classList.add('ai-active'); 
        
        const mainImg = document.getElementById('ai-dpb-preview-img');
        mainImg.classList.add('ai-dpb-blur-loading');

        document.getElementById('ai-btn-generate').innerText = "กำลังประมวลผล...";

        // 1. Get Attributes (External IDs)
        const getTxt = (id) => { 
            const el = document.getElementById(id); 
            return (el && el.selectedOptions && el.selectedOptions[0]) ? el.selectedOptions[0].text.trim() : 'Wood'; 
        };
        const material = getTxt('dpb-top-color'); // External ID
        const legs = getTxt('dpb-legs'); // External ID
       
        // 2. Construct Prompts
        let styleDescription = "";
        let elemPrompt = "";
     
        switch(state.style) {
            case 'nature':
                styleDescription = `a modern home office with nature integration, view of a beautiful lush green garden through a window on the left, indoor potted plants, soft natural daylight, serene atmosphere`;
                break;
            case 'executive':
                styleDescription = `a luxury executive office corner, built-in wooden bookshelves filled with books and premium decor, sophisticated atmosphere, warm lighting`;
                break;
            case 'gamer':
                styleDescription = `A cinematic photograph of a cozy gamer room setup. Dim and moody environment. Diffused RGB LED strips hidden behind the desk, casting soft indirect glow. Warm neon ambient lighting. High contrast, deep shadows, immersive vibe`;
                break;
            case 'minimal':
            default:
                styleDescription = `a minimalist room corner, flooring made of ${material} vinyl planks arranged in a staggered pattern, white walls. Lighting: Soft natural light coming from the left, filtered through sheer curtains (not visible), casting gentle soft shadows on the floor`;
                break;
        }

        const selectedElem = state.element || []; 

        // [LOGIC FIX APPLIED] Separation of checks + concatenation
        if (selectedElem.includes('nebula')) {
            elemPrompt += ", add a Nebula Chair(Nebula_Back_ISO2.png) in front the desk";
        }
        
        if (selectedElem.includes('pc')) {
            elemPrompt += ", add a Desktop PC setup on the table, widescreen monitor black screen, bluetooth mechanical keyboard wireless, bluetooth gaming mouse wireless with mouse pad, and a computer tower case under the desk without wire.";
        } 
        
        if (selectedElem.includes('laptop')) {
             elemPrompt += ", add a Productivity setup on the table, open laptop computer black screen, iPad tablet, Iphone 17 pro max, bluetooth mechanical keyboard wireless, bluetooth gaming mouse wireless with mouse pad";
        }

        const basePrompt = `Based on (input_desk_render.png). Change the background to ${styleDescription}${elemPrompt}. High quality, photorealistic, deep depth of field, sharp focus everywhere, everything in focus, crystal clear background, Shot on Phase One XF IQ4 150MP.`.replace(/\n/g, " ");
        const negPrompt = `(change perspective), (change angle), (zoom out), (alter desk shape), window frame, floating objects, galaxy pattern, space theme, low resolution, bad reflection, distorted textures, noise, grain, watermark, text, signature, bad geometry, extra legs, cartoon, painting`.replace(/\n/g, " ");

        // 3. Simulation
        let p = 0;
        const pBar = document.getElementById('ai-progress-fill');
        const pTxt = document.getElementById('ai-status-text');
        
        pBar.style.width = '0%';
        pTxt.style.color = 'var(--ai-dpb-gold)';

        state.timer = setInterval(() => {
            if(p < 90) {
                p += Math.random() * 2;
                pBar.style.width = p + '%';
                if(p < 30) pTxt.innerText = "Analyzing 3D Model...";
                else if(p < 60) pTxt.innerText = "Generating " + state.style + " Environment...";
                else pTxt.innerText = "Finalizing Details...";
            }
        }, 300);

        try {
            // 4. API Call
            const response = await fetch(CONFIG.webhookUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    image_base64: getResizedDataURL(canvas, 1920, 1477),
                    prompt_details: basePrompt,
                    negative_prompt: negPrompt,
                    style_selected: state.style,
                    element_selected: selectedElem,
                    ref_image_url: selectedElem.includes('nebula') ? CONFIG.chairImage : '',
                    user: CONFIG.user
                })
            });

            clearInterval(state.timer);

            if(!response.ok) throw new Error("Connection Failed");

            const blob = await response.blob(); 
            const finalUrl = URL.createObjectURL(blob); 

            // 5. Success Handling
            pBar.style.width = '100%';
            pTxt.innerText = "Completed!";
            
            const img = new Image();
            img.onload = () => {
                const displayImg = document.getElementById('ai-dpb-preview-img');
                displayImg.src = finalUrl;
                displayImg.classList.remove('ai-dpb-blur-loading'); 

                document.getElementById('ai-loading-overlay').classList.remove('ai-active');
                document.getElementById('ai-dpb-display-box').classList.add('ai-finished');
                
                const dl = document.getElementById('ai-link-download');
                dl.href = finalUrl;
                dl.download = `DeskSpace-${state.style}-${Date.now()}.png`;

                updateCredit(); 
                addToHistory(finalUrl);

                state.isLoading = false; 
                document.getElementById('ai-btn-generate').disabled = false;
                document.getElementById('ai-btn-generate').innerHTML = `${ICONS.magic} Generate Image`;
            };
            img.onerror = () => { throw new Error("Image Load Failed"); };
            img.src = finalUrl;

        } catch(e) {
            console.error(e);
            clearInterval(state.timer);
            
            document.getElementById('ai-loading-overlay').classList.remove('ai-active');
            resetToCanvasOriginal();

            const errArea = document.getElementById('ai-dpb-error-msg');
            errArea.innerText = "Error: " + e.message + " (Please try again)";
            errArea.classList.add('ai-visible');

            state.isLoading = false;
            document.getElementById('ai-btn-generate').disabled = false;
            document.getElementById('ai-btn-generate').innerHTML = `${ICONS.refresh} Try Again`;
        }
    };

    async function updateCredit() {
        try {
            const res = await fetch('https://api.kie.ai/api/v1/chat/credit');
            if(res.ok) {
                const data = await res.json();
                updateCreditDisplay(data.data || data.credit);
            }
        } catch(e){}
    }

    function updateCreditDisplay(val) {
        if(val) {
             const remaining = Math.floor(parseInt(val) / 4);
             document.getElementById('ai-txt-credit').innerText = remaining;
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

<script>
const dsAuthModal = {
    el: document.getElementById('ds-auth-modal'),
    
    // [แก้ไข 1] เช็คสถานะ Login จาก PHP โดยตรง (ไม่ต้องรอตัวแปร global)
    isLogged: <?php echo is_user_logged_in() ? 'true' : 'false'; ?>,
    
    // [แก้ไข 2] ดึงข้อมูล User จาก PHP โดยตรง
    currentUser: <?php 
        $u = wp_get_current_user();
        $userData = array();
        if ( $u->exists() ) {
            $userData = (array) $u->data;
            $userData['roles'] = $u->roles;
        }
        echo json_encode($userData); 
    ?>,
    
    // [แก้ไข 3] สร้าง Nonce และ URL ตรงนี้เลย
    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce('ds_auth_nonce'); ?>',
    logoutUrl: '<?php echo esc_js(wp_logout_url(home_url())); ?>',

    // --- ส่วน Logic ด้านล่างเหมือนเดิม ไม่ต้องแก้ไข ---
    open: function() {
        this.el.classList.add('open');
        this.resetMsg();
        if(this.isLogged) {
            this.switchView('profile');
            // เช็คว่ามีข้อมูล User จริงไหม ป้องกัน Error
            let displayName = 'User';
            if (this.currentUser && (this.currentUser.display_name || this.currentUser.user_login)) {
                displayName = this.currentUser.display_name || this.currentUser.user_login;
            }
            document.getElementById('ds-profile-name').innerText = displayName;
            
            let roleName = 'Member';
            if (this.currentUser && this.currentUser.roles && this.currentUser.roles.length > 0) {
                roleName = this.currentUser.roles[0];
            }
            document.getElementById('ds-profile-role').innerText = roleName.charAt(0).toUpperCase() + roleName.slice(1);
        } else {
            this.switchView('login');
        }
    },
    
    close: function() { this.el.classList.remove('open'); },

    switchView: function(viewName) {
        document.querySelectorAll('.ds-view').forEach(el => el.style.display = 'none');
        document.getElementById('ds-view-' + viewName).style.display = 'block';
        this.resetMsg();
        const title = document.getElementById('ds-modal-title');
        const desc = document.getElementById('ds-modal-desc');
        
        // เช็คว่า element มีอยู่จริงไหมก่อน set innerText เพื่อป้องกัน error ในบางหน้า
        if(title) {
             if(viewName === 'login') { title.innerText='เข้าสู่ระบบ'; desc.innerText='เข้าสู่ระบบเพื่อดำเนินการต่อ'; }
             else if(viewName === 'register') { title.innerText='สมัครสมาชิก'; desc.innerText='สร้างบัญชีใหม่เพื่อเริ่มออกแบบ'; }
             else if(viewName === 'forgot') { title.innerText='ลืมรหัสผ่าน?'; desc.innerText='ระบุอีเมลเพื่อตั้งรหัสใหม่'; }
             else if(viewName === 'profile') { title.innerText='ข้อมูลบัญชี'; desc.innerText='จัดการข้อมูลของคุณ'; }
             else if(viewName === 'logout-confirm') { 
                 title.innerText='ออกจากระบบ'; 
                 if(desc) desc.innerText=''; 
             }
        }
    },

    confirmLogout: function() {
        this.showMsg('กำลังออกจากระบบ...', 'success');
        
        let fd = new FormData();
        fd.append('action', 'ds_ajax_logout');
        fd.append('security', this.nonce);

        fetch(this.ajaxUrl, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                window.location.reload();
            } else {
                window.location.href = this.logoutUrl;
            }
        })
        .catch(err => {
            console.error(err);
            window.location.reload();
        });
    },

    togglePwd: function(btn) {
        const input = btn.previousElementSibling;
        if(input.type === 'password'){ 
            input.type = 'text'; 
            btn.style.opacity = '1'; 
        } else { 
            input.type = 'password'; 
            btn.style.opacity = '0.4'; 
        }
    },

    resetMsg: function() {
        const msg = document.getElementById('ds-msg-box');
        if(msg) { msg.style.display = 'none'; msg.className = ''; msg.innerText = ''; }
    },
    showMsg: function(text, type) {
        const msg = document.getElementById('ds-msg-box');
        if(msg) {
            msg.innerText = text;
            msg.className = (type === 'error') ? 'ds-msg-error' : 'ds-msg-success';
            msg.style.display = 'block';
        }
    },

    _send: function(action, formData, callback) {
        formData.append('action', action);
        formData.append('security', this.nonce);
        const btn = document.querySelector('.ds-view[style*="block"] button[type="submit"]');
        const originalText = btn ? btn.innerText : '';
        if(btn) { btn.disabled = true; btn.innerText = 'กำลังประมวลผล...'; }

        fetch(this.ajaxUrl, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(btn) { btn.disabled = false; btn.innerText = originalText; }
            if (data.success) {
                callback({ success: true, data: data.data });
            } else {
                callback({ success: false, data: data.data });
            }
        })
        .catch(err => {
            console.error(err);
            if(btn) { btn.disabled = false; btn.innerText = originalText; }
            this.showMsg('เกิดข้อผิดพลาดในการเชื่อมต่อ (Server Error)', 'error');
        });
    },

    submitLogin: function(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        this._send('ds_ajax_login', fd, (res) => {
            if(res.success) {
                this.showMsg('เข้าสู่ระบบสำเร็จ! กำลังรีโหลด...', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else { 
                this.showMsg(res.data || 'Login Failed', 'error'); 
            }
        });
    },
    submitRegister: function(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        this._send('ds_ajax_register', fd, (res) => {
            if(res.success) {
                this.showMsg('สมัครสมาชิกสำเร็จ! กำลังเข้าสู่ระบบ...', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else { 
                this.showMsg(res.data || 'Register Failed', 'error'); 
            }
        });
    },
    submitForgot: function(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        this._send('ds_ajax_forgot', fd, (res) => {
            if(res.success) {
                this.showMsg('ส่งลิงก์รีเซ็ตไปทางอีเมลแล้ว', 'success');
                e.target.reset();
            } else { 
                this.showMsg(res.data || 'Error', 'error'); 
            }
        });
    }
};
</script>


/* ======================================
Code Position Option
====================================== */
	
<style id="dpb-pp3-css">

:root {
  --desk-top-1: #f0ebe0;
  --desk-top-2: #e2d8c6;
  --desk-border: #c8b892;
  --desk-woodline: #7a5c20;
}

/* ======================================
   POSITION PICKER v3 — CSS
====================================== */
#dpb-pp3 {
  position:fixed;inset:0;z-index:1000000000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;
  transition:opacity .25s ease;
}
#dpb-pp3.open { opacity:1;pointer-events:all; }

#dpb-pp3__bd {
  position:absolute;inset:0;
  background:rgba(0, 0, 0, .35);
  -webkit-backdrop-filter:blur(6px);
  cursor:pointer;
}

#dpb-pp3__panel {
  position:relative;z-index:1;
  background:#fff;border-radius:22px;
  width:min(490px,calc(100vw - 24px));
  letter-spacing:0.8px;
  max-height:calc(100dvh - 36px);
  overflow-y:auto;overflow-x:hidden;
  box-shadow:0 28px 80px rgba(0,0,0,.24),0 0 0 1px rgba(0,0,0,.06);
  transform:translateY(22px) scale(.975);
  transition:transform .32s cubic-bezier(.34,1.56,.64,1);
  scrollbar-width:none;
}
#dpb-pp3.open #dpb-pp3__panel { transform:none; }
#dpb-pp3__panel::-webkit-scrollbar { display:none; }

/* Header */
.pp3-hd { padding:24px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px; }
.pp3-tag {
  display:inline-flex;align-items:center;gap:5px;
  background:#f5f0e8;color:#b69652;
  font-size:11px;font-weight:500;letter-spacing:1px;text-transform:uppercase;
  padding:4px 10px;border-radius:20px;margin-bottom:7px;
}
.pp3-title { font-size:22px;font-weight:600;color:#111;margin:0 0 3px;line-height:1.25; }
.pp3-sub   { font-size:12px;color:#888;margin:0; }
.pp3-x {
  flex-shrink:0;width:34px;height:34px;border-radius:50%;
  background:#f4f4f4;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  color:#666;transition:background .15s;
}
.pp3-x:hover { background:#e8e8e8;color:#111; }

/* Body */
.pp3-body { padding:18px 24px 0; }

/* Option card */
.pp3-card {
  display:flex;align-items:center;gap:12px;
  background:#fafafa;border:1px solid #efefef;border-radius:13px;
  padding:11px 13px;margin-bottom:18px;
}
.pp3-thumb { width:48px;height:48px;border-radius:9px;overflow:hidden;flex-shrink:0;background:#eee; }
.pp3-thumb img { width:100%;height:100%;object-fit:cover; }
.pp3-cname { font-size:13px;font-weight:600;color:#111;margin:0 0 2px; }
.pp3-cdim  { font-size:11px;color:#999;margin:0; }
.pp3-cbadge {
  margin-left:auto;background:#111;color:#fff;
  font-size:12px;font-weight:700;
  width:26px;height:26px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}

/* Piece label */
.pp3-piece {
  text-align:center;font-size:12px;font-weight:600;
  color:#b69652;text-transform:uppercase;letter-spacing:.05em;margin-bottom:13px;
}

/* Desk SVG */
.pp3-desk { width:100%;display:block;overflow:visible;margin-bottom:6px; }

/* Zones */
.pp3-zone { cursor:pointer;outline:none; }
.pp3-zbg {
  fill:transparent;stroke:rgba(182,150,82,.15);
  stroke-width:1.5;stroke-dasharray:5 3;
  transition:all .18s;
}
.pp3-zone:hover .pp3-zbg,
.pp3-zone:focus-visible .pp3-zbg { fill: rgb(255 255 255 / 14%); stroke: rgb(255 255 255 / 47%); }
.pp3-zone[aria-pressed="true"] .pp3-zbg { fill:rgba(182,150,82,.6);stroke:#b69652;stroke-width:2;stroke-dasharray:0; }

/* Indicator rect */
.pp3-zi-rect {
  fill:rgba(182,150,82,.08);
  stroke:rgba(182,150,82,.75);
  stroke-width:1.5;
  stroke-dasharray:3 2;
  transition:all .18s;
}
.pp3-zone:hover .pp3-zi-rect,
.pp3-zone:focus-visible .pp3-zi-rect { fill:#8a713c; }
.pp3-zone[aria-pressed="true"] .pp3-zi-rect { fill:rgba(255,255,255,.95);stroke-width:0; }
.pp3-zi-rect.is-existing {
    fill: #8a713c;
    stroke: none;
    stroke-dasharray: 0;
}

/* Indicator text */
.pp3-zi-text {
  fill:#b69652;
  font-size:7.5px;font-weight:500;
  font-family:'Prompt',monospace,sans-serif;
  text-anchor:middle;
  dominant-baseline:central;
  pointer-events:none;letter-spacing:0;
  transition:fill .18s;
}
.pp3-zi-text.is-existing { fill:#fff; }
.pp3-zone[aria-pressed="true"] .pp3-zi-text { fill:#b69652; }

/* Zone label — FIX #5: dominant-baseline:middle เพื่อให้อยู่กึ่งกลาง Y จริงๆ */
.pp3-zlbl {
  fill:#fff;
  font-size:12px;font-weight:500;
  font-family:'Prompt',sans-serif;
  text-anchor:middle;
  dominant-baseline:middle;   /* ← แก้จาก central เป็น middle */
  pointer-events:none;
  letter-spacing:.04em;
  transition:fill .18s;
}
.pp3-zone:hover .pp3-zlbl,
.pp3-zone:focus-visible .pp3-zlbl { fill:#fff; }
.pp3-zone[aria-pressed="true"] .pp3-zlbl { fill:#fff; }

h2#pp3-title { letter-spacing:1px; }

/* Warning */
.pp3-warn {
  display:none;align-items:flex-start;gap:9px;
  background:#fff8f0;border:1px solid rgba(255,152,0,.32);
  border-radius:10px;padding:10px 12px;margin-bottom:13px;
  font-size:12px;color:#7c4d00;line-height:1.5;
}
.pp3-warn.show { display:flex; }
.pp3-warn svg  { flex-shrink:0;color:#ff9800;margin-top:1px; }

/* Offset box */
.pp3-off {
  display:none;background:#f7f4ef;border-radius:11px;
  padding:14px 16px;margin-bottom:16px;
}
.pp3-off.show { display:block; }
.pp3-off-ttl {
  font-size:14px;font-weight:500;color:#b69652;
  text-transform:uppercase;letter-spacing:.7px;margin:0 0 5px;
}

/* Auto info */
.pp3-info-auto {
  display:none;align-items:flex-start;gap:6px;
  background:rgba(182,150,82,.1);border-radius:6px;
  padding:8px 10px;margin-bottom:12px;
  font-size:11px;color:#9a7a30;line-height:1.4;
}
.pp3-info-auto.show { display:flex; }
.pp3-info-auto svg { flex-shrink:0;color:#b69652;margin-top:1px; }

.pp3-off-row  { display:flex;gap:20px;flex-wrap:wrap; }
.pp3-off-item { display:flex;align-items:center;gap:8px;font-size:12px;color:#666;font-weight:500; }
.pp3-num-input {
  width:65px;height:32px;font-size:13px;font-weight:500;color:#111;
  text-align:center;border:1.5px solid rgba(182,150,82,.3);
  border-radius:8px;background:#fff;font-family:inherit;outline:none;transition:all .15s;
}
.pp3-num-input:focus { border-color:#b69652;box-shadow:0 0 0 3px rgba(182,150,82,.15); }

/* Footer */
.pp3-ft { padding:12px 24px 24px;display:flex;gap:10px;align-items:center;justify-content:flex-end; }
.pp3-back {
  display:flex;align-items:center;gap:6px;padding:9px 15px;border-radius:10px;
  border:1.5px solid #ddd;background:transparent;
  font-size:13px;font-weight:500;color:#666;cursor:pointer;font-family:inherit;transition:all .15s;
}
.pp3-back:hover { background:#f5f5f5;border-color:#ccc; }
.pp3-ok {
  display:flex;align-items:center;gap:7px;padding:10px 20px;
  border-radius:10px;border:none;background:#b69652;color:#fff;
  font-size:14px;letter-spacing:0.8px;font-weight:500;cursor:pointer;font-family:inherit;
  box-shadow:0 4px 14px rgba(182,150,82,.35);transition:all .18s;
}
.pp3-ok:hover:not(:disabled) { box-shadow:0 6px 20px rgba(182,150,82,.45); }
.pp3-ok:active:not(:disabled) { transform:scale(.97); }
.pp3-ok:disabled { background:#ccc;box-shadow:none;cursor:not-allowed;transform:none;opacity:0.7; }

/* Ripple */
@keyframes pp3rip { from{transform:scale(0);opacity:.5} to{transform:scale(5);opacity:0} }
.pp3-rip {
  position:fixed;border-radius:50%;background:rgba(182,150,82,.32);
  width:28px;height:28px;margin:-14px 0 0 -14px;
  pointer-events:none;z-index:999999;animation:pp3rip .5s ease-out forwards;
}
@media(max-width:480px){
  #dpb-pp3 { align-items:flex-end; }
  #dpb-pp3__panel { border-radius:20px 20px 0 0;width:100%;max-height:91dvh;letter-spacing:0.8px; }
  .pp3-hd,.pp3-body { padding-left:18px;padding-right:18px; }
  .pp3-ft { padding-left:18px;padding-right:18px; }
}
</style>

<div id="dpb-pp3" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="pp3-title">
  <div id="dpb-pp3__bd"></div>
  <div id="dpb-pp3__panel">

    <div class="pp3-hd">
      <div>
        <div class="pp3-tag">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
          Step 2 — เลือกตำแหน่ง
        </div>
        <h2 class="pp3-title" id="pp3-title">วางตำแหน่งบนโต๊ะ</h2>
        <p class="pp3-sub">แตะโซนที่ต้องการบนท็อปโต๊ะด้านล่าง</p>
      </div>
      <button class="pp3-x" id="pp3-x" type="button" aria-label="ปิด">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="pp3-body">
      <div class="pp3-card">
        <div class="pp3-thumb"><img id="pp3-img" src="" alt=""></div>
        <div>
          <p class="pp3-cname" id="pp3-name">—</p>
          <p class="pp3-cdim"  id="pp3-dim">—</p>
        </div>
        <div class="pp3-cbadge" id="pp3-badge">1</div>
      </div>

      <div class="pp3-piece" id="pp3-piece" style="display:none">
        กำลังเลือกตำแหน่ง ชิ้นที่ <strong id="pp3-pn">1</strong> / <span id="pp3-pt">1</span>
      </div>

      <svg class="pp3-desk" id="pp3-desk-svg" viewBox="0 0 440 210" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="pp3-dg" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--desk-top-1)"/>
            <stop offset="100%" stop-color="var(--desk-top-2)"/>
          </linearGradient>
          <filter id="pp3-sh"><feDropShadow dx="0" dy="5" stdDeviation="7" flood-color="rgba(0,0,0,.1)"/></filter>
          <clipPath id="pp3-clip"><rect x="18" y="18" width="404" height="145" rx="10"/></clipPath>
        </defs>

        <rect x="18" y="18" width="404" height="145" rx="10" fill="#b69652" stroke="var(--desk-border)" stroke-width="1.5"/>


        <!-- ZONE: top-left  x=22  w=124  rightEdge=146 -->
        <g class="pp3-zone" id="pp3-zone-top-left" data-zone="top-left" role="button" tabindex="0" aria-pressed="false">
          <rect class="pp3-zbg" id="pp3-zbg-top-left" x="22" y="22" width="124" height="135" rx="8"/>
          <g id="pp3-zind-top-left"></g>
          <text class="pp3-zlbl" id="pp3-zlbl-top-left"   x="84"  y="89.5">บนซ้าย</text>
        </g>

        <!-- ZONE: top-center  x=156  w=128  cx=220 -->
        <g class="pp3-zone" id="pp3-zone-top-center" data-zone="top-center" role="button" tabindex="0" aria-pressed="false">
          <rect class="pp3-zbg" id="pp3-zbg-top-center" x="156" y="22" width="128" height="135" rx="8"/>
          <g id="pp3-zind-top-center"></g>
          <text class="pp3-zlbl" id="pp3-zlbl-top-center" x="220" y="89.5">บนกลาง</text>
        </g>

        <!-- ZONE: top-right  x=294  w=124  rightEdge=418  cx=356 -->
        <g class="pp3-zone" id="pp3-zone-top-right" data-zone="top-right" role="button" tabindex="0" aria-pressed="false">
          <rect class="pp3-zbg" id="pp3-zbg-top-right" x="294" y="22" width="124" height="135" rx="8"/>
          <g id="pp3-zind-top-right"></g>
          <text class="pp3-zlbl" id="pp3-zlbl-top-right"  x="356" y="89.5">บนขวา</text>
        </g>

        <line x1="151" y1="28" x2="151" y2="148" stroke="rgba(182,150,82,.2)" stroke-width="1" stroke-dasharray="4 3"/>
        <line x1="289" y1="28" x2="289" y2="148" stroke="rgba(182,150,82,.2)" stroke-width="1" stroke-dasharray="4 3"/>

        <text x="220" y="10"  text-anchor="middle" font-size="11.5" fill="#aaa" font-family="Prompt,sans-serif">ด้านบน (ติดผนัง)</text>
        <text x="220" y="202" text-anchor="middle" font-size="10"   fill="#aaa" font-family="Prompt,sans-serif">หากต้องการเลือกตำแหน่งอื่นนอกเหนือจากนี้ กรุณาตั้งค่าที่ปุ่ม ตั้งค่าตำแหน่ง Options</text>
      </svg>

      <div class="pp3-warn" id="pp3-warn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span id="pp3-warn-txt"></span>
      </div>

      <div class="pp3-off" id="pp3-off">
        <div class="pp3-off-ttl">กำหนดระยะห่าง (Offset)</div>
        <div id="pp3-info-auto" class="pp3-info-auto">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="10" x2="12" y2="18"></line><line x1="12" y1="5" x2="12.01" y2="8"></line></svg>
          <span>ระบบได้คำนวณและเว้นระยะห่างให้อัตโนมัติ</span>
        </div>
        <div class="pp3-off-row">
          <div class="pp3-off-item" id="pp3-box-ox">
            <span id="pp3-ox-lbl">จากขอบซ้าย:</span>
            <input type="number" id="pp3-ox-input" class="pp3-num-input" min="0" step="1">
            <span>cm</span>
          </div>
          <div class="pp3-off-item" id="pp3-box-oy">
            <span id="pp3-oy-lbl">จากขอบบน:</span>
            <input type="number" id="pp3-oy-input" class="pp3-num-input" min="0" step="1">
            <span>cm</span>
          </div>
        </div>
      </div>

    </div>

    <div class="pp3-ft">
      <button type="button" class="pp3-back" id="pp3-back">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        ย้อนกลับ
      </button>
      <button type="button" class="pp3-ok" id="pp3-ok" disabled>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="pp3-ok-lbl">ยืนยัน & เพิ่มลงโต๊ะ</span>
      </button>
    </div>

  </div>
</div>

<script>
/* ====================================================
   DPB POSITION PICKER v3.2
   
   FIX LOG:
   #1  offsetX reset ตอนเพิ่มชิ้นที่ 3+
       → set ค่าหลัง buildOptConfig() เสร็จ โดยใช้ pending queue
   #2  top-center indicator อยู่ซ้าย
       → คำนวณ sx จากกึ่งกลาง zone ที่ถูกต้อง (cx=220)
       → สลับ: i=0 กลาง, i=1 ขวา +step, i=2 ซ้าย -step, ...
   #3  top-right indicator อยู่ซ้าย
       → slot[0] ชิดขวาสุด: sx = zd.x+zd.w-IND_PAD-IND_W = 418-6-32 = 380
       → slot[i] เรียงไปซ้าย
   #4  wrap ขึ้น row ใหม่เมื่อ sx ออกนอก boundary ของ zone
   #5  pp3-zlbl ไม่อยู่กึ่งกลาง Y
       → dominant-baseline:middle + JS set y = zd.y + zd.h/2
==================================================== */
(function(){
'use strict';

/* ================================================================
   ZONE GEOMETRY (SVG coordinates)
   top-left  : x=22,  w=124  → rightEdge=146  divider at x=151
   top-center: x=156, w=128  → cx=220         dividers at 151,289
   top-right : x=294, w=124  → rightEdge=418  divider at x=289
================================================================ */
const ZONE_DEF = {
  'top-left':   { x:22,  y:22, w:124, h:135, place:'left',   from:'top', defaultOx:10, defaultOy:5 },
  'top-center': { x:156, y:22, w:128, h:135, place:'center', from:'top', defaultOx:0,  defaultOy:5 },
  'top-right':  { x:294, y:22, w:124, h:135, place:'right',  from:'top', defaultOx:10, defaultOy:5 },
};

/*
  ขอบซ้าย/ขวาของแต่ละ zone ที่ indicator ห้ามเกิน
  (ใช้ขอบ zone จริงๆ ไม่ใช่ divider เพราะ indicator อยู่ภายใน zone เท่านั้น)
*/
const ZONE_LIMIT = {
  'top-left':   { xMin: 22,  xMax: 146 },   /* x + w - 0 */
  'top-center': { xMin: 156, xMax: 284 },   /* x + w - 0 */
  'top-right':  { xMin: 294, xMax: 418 },   /* x + w - 0 */
};

/* indicator slot dimensions */
const IND_W       = 32;   /* ความกว้าง rect ต่อ slot */
const IND_H       = 17;   /* ความสูง rect */
const IND_GAP     = 3;    /* ช่องว่างระหว่าง slot แนวนอน */
const IND_PAD     = 5;    /* padding ด้านข้างจากขอบ zone */
const IND_TOP     = 4;    /* ห่างจากขอบบนของ zone (y) */
const IND_ROW_GAP = 4;    /* ช่องว่างระหว่าง row */

const HOLE_TYPES  = ['hole_rect','hole_circle','track'];
const MIN_X = 10;
const MIN_Y = 5;
const GAP   = 5;

/* ================================================================
   DOM
================================================================ */
const $       = id => document.getElementById(id);
const modal   = $('dpb-pp3');
const bd      = $('dpb-pp3__bd');
const xBtn    = $('pp3-x');
const backBtn = $('pp3-back');
const okBtn   = $('pp3-ok');
const okLbl   = $('pp3-ok-lbl');
const imgEl   = $('pp3-img');
const nameEl  = $('pp3-name');
const dimEl   = $('pp3-dim');
const badgeEl = $('pp3-badge');
const pieceEl = $('pp3-piece');
const pnEl    = $('pp3-pn');
const ptEl    = $('pp3-pt');
const warnEl  = $('pp3-warn');
const warnTxt = $('pp3-warn-txt');
const offEl   = $('pp3-off');
const boxOx   = $('pp3-box-ox');
const boxOy   = $('pp3-box-oy');
const oxInput = $('pp3-ox-input');
const oyInput = $('pp3-oy-input');
const oxLbl   = $('pp3-ox-lbl');
const oyLbl   = $('pp3-oy-lbl');
const infoAuto= $('pp3-info-auto');
const zones   = document.querySelectorAll('.pp3-zone');

if (!modal) return;

/* ================================================================
   STATE
================================================================ */
let S = {};

function resetState(opts) {
  S = {
    open:    false,
    optKey:  opts.optKey,
    opMeta:  opts.opMeta,
    variant: opts.variant || '',
    qty:     opts.qty || 1,
    origFn:  opts.origFn,
    cbDone:  opts.cbDone,
    cbBack:  opts.cbBack,
    done:    [],
    cur:     0,
    zone:    null,
  };
}

/* ================================================================
   SVG HELPER
================================================================ */
const SVG_NS = 'http://www.w3.org/2000/svg';
function svgEl(tag, attrs, text) {
  const el = document.createElementNS(SVG_NS, tag);
  if (attrs) Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
  if (text !== undefined) el.textContent = text;
  return el;
}

/* ================================================================
   ABBREVIATION  "Popup Socket (Wireless)" → "PSW"
================================================================ */
function makeAbbr(name) {
  const cleaned = String(name || '').replace(/[()[\]{}&]/g, ' ');
  const words   = cleaned.split(/\s+/).filter(Boolean);
  if (words.length <= 2) {
    /* 1-2 คำ: เอาหัวทุกคำ เช่น Soft Close → SC */
    return words.map(w => w[0].toUpperCase()).join('');
  }
  /* 3+ คำ: เอาแค่คำแรกกับคำสุดท้าย เช่น Mini Soft Close → MC */
  return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}
/* totalInKey = จำนวนทั้งหมดของ option นั้น (existing + session)
   ถ้า totalInKey === 1 ไม่ต้องมีเลข */
function makeLabel(abbr, idx, totalInKey) {
  if (totalInKey <= 1) return abbr;
  return abbr + idx;
}
/* ================================================================
   BUILD INDICATOR ITEMS สำหรับ zone หนึ่งๆ
   รวม: existing (optConfig) + session done + current active slot
   *** แต่ละ item จะมี ox (offsetX จริง cm) และ w (กว้าง cm) ด้วย
       เพื่อให้ render indicator ตามตำแหน่งจริงบนท็อปโต๊ะ ***
================================================================ */
function buildIndicatorItems(zone) {
  const zd    = ZONE_DEF[zone];
  const gs    = window.state;
  const items = [];

  /* 1. existing จาก optConfig */
  if (gs && gs.optConfig) {
    let globalIdx = 0;
    Object.entries(gs.optConfig).forEach(([key, arr]) => {
      const op     = ((gs.meta && gs.meta.options) || []).find(o => o.key === key) || {};
      const abbr   = makeAbbr(op.name || key);
      const opType = String(op.type || '').toLowerCase();
      if (!HOLE_TYPES.includes(opType)) {
        globalIdx += (arr || []).length;
        return;
      }
      const totalInKey = (arr || []).length; /* จำนวนทั้งหมดของ key นี้ */
      (arr || []).forEach((cfg, localIdx) => {
        globalIdx++;
        if (cfg.place === zd.place && cfg.from === zd.from) {
          items.push({
            label: makeLabel(abbr, localIdx + 1, totalInKey),
            isExisting: true,
            ox: Number(cfg.offsetX) || MIN_X,
            w:  Number(cfg.w) || Number(op.defaultWcm) || 10,
            type: opType,
            key:  key,
            variant: cfg.variant || '',
          });
        }
      });
    });
  }

  /* 2. session done */
  const sessAbbr = makeAbbr((S.opMeta && S.opMeta.name) || S.optKey || '');
  let existingBase = 0;
  if (gs && gs.optConfig && gs.optConfig[S.optKey]) {
    existingBase = (gs.optConfig[S.optKey] || []).length;
  }
  /* totalInKey สำหรับ session = existing + qty ที่จะเพิ่ม */
  const sessTotalInKey = existingBase + S.qty;

  S.done.forEach((d, idx) => {
    if (d.zone === zone) {
      const op = S.opMeta || {};
      items.push({
        label: makeLabel(sessAbbr, existingBase + idx + 1, sessTotalInKey),
        isExisting: false,
        ox: Number(d.ox) || MIN_X,
        w:  Number(op.defaultWcm) || 10,
        type: String(op.type || '').toLowerCase(),
        key:  S.optKey,
        variant: S.variant || '',
      });
    }
  });

  /* 3. current slot */
  if (S.zone === zone) {
    const currentOx = (zone !== 'top-center') ? (parseFloat(document.getElementById('pp3-ox-input')?.value) || MIN_X) : 0;
    const op = S.opMeta || {};
    items.push({
      label: makeLabel(sessAbbr, existingBase + S.cur + 1, sessTotalInKey),
      isExisting: false,
      isCurrent: true,
      ox: currentOx,
      w:  Number(op.defaultWcm) || 10,
      type: String(op.type || '').toLowerCase(),
      key:  S.optKey,
      variant: S.variant || '',
    });
  }

  return items;
}

/* SVG desk boundaries (ท็อปโต๊ะทั้งหมด) */
const DESK_LEFT  = 22;   /* x ซ้ายสุดของท็อปโต๊ะ */
const DESK_RIGHT = 418;  /* x ขวาสุดของท็อปโต๊ะ */
const DESK_TOP_Y = 22;   /* y ของ indicator row แรก (zone y) */

/* x ที่ label ของแต่ละ zone เริ่ม (indicator ห้ามทับ label) */
/* label อยู่กึ่งกลาง zone → indicator ต้องหยุดก่อน zone ถัดไปเริ่ม
   ถ้า zone ถัดไปมี item: ใช้ divider เป็น barrier
   ถ้า zone ถัดไปว่าง: ข้ามผ่านได้ */
const ZONE_DIVIDERS = {
  'top-left':   { right: 151 },  /* divider ระหว่าง left กับ center */
  'top-center': { left: 151, right: 289 },
  'top-right':  { left: 289 },
};
/* ================================================================
   SHAPE SIZE — ย้ายออกนอก renderAllIndicators
================================================================ */
function getShapeSize(item) {
  if (item.key === 'mini_soft_close') return { w: 15, h: 15, shape: 'rect' };
  if (item.type === 'track' && item.key === 'power_track') {
    if (item.variant === '100cm') return { w: 120, h: 10, shape: 'rect' };
    return { w: 100, h: 10, shape: 'rect' };
  }
  switch (item.type) {
    case 'hole_rect':   return { w: 50, h: 20, shape: 'rect' };
    case 'hole_circle': return { w: 20, h: 20, shape: 'circle' };
    case 'track':       return { w: 100, h: 10, shape: 'rect' };
    default:            return { w: IND_W, h: IND_H, shape: 'rect' };
  }
}

function renderAllIndicators() {
  const allItems = {};
  Object.keys(ZONE_DEF).forEach(z => {
    allItems[z] = buildIndicatorItems(z);
  });

  Object.keys(ZONE_DEF).forEach(zone => {
    const zd    = ZONE_DEF[zone];
    const indG  = $('pp3-zind-' + zone);
    const zbgEl = $('pp3-zbg-' + zone);
    const lblEl = $('pp3-zlbl-' + zone);
    if (!indG || !zbgEl) return;

    indG.innerHTML = '';

    const items = allItems[zone];
    const count = items.length;

    if (count === 0) {
      if (lblEl) {
        lblEl.setAttribute('x', zd.x + zd.w / 2);
        lblEl.setAttribute('y', zd.y + zd.h / 2);
      }
      return;
    }

    /* ========== barrier ========== */
    let rightBarrier = DESK_RIGHT - IND_PAD;
    if (zone === 'top-left') {
      if (allItems['top-center'].length > 0)
        rightBarrier = ZONE_DIVIDERS['top-left'].right - IND_PAD;
      else if (allItems['top-right'].length > 0)
        rightBarrier = ZONE_DIVIDERS['top-center'].right - IND_PAD;
    } else if (zone === 'top-center') {
      if (allItems['top-right'].length > 0)
        rightBarrier = ZONE_DIVIDERS['top-center'].right - IND_PAD;
    }

    const y0   = zd.y + IND_TOP;
    const rowH = IND_H + IND_ROW_GAP;

    /* positions เก็บ { sx, sy, row, shapeW, shapeH, shape, cx }
       sx/sy = ตำแหน่งจริงของ shape (ไม่ใช่ slot อีกต่อไป)
       cx    = x กึ่งกลางสำหรับวาง text
    */
    const positions = [];

    /* ---- helper: clamp shape ไม่ให้ทะลุขอบโต๊ะ ---- */
    function clampX(rx, shapeW) {
      const minRx = DESK_LEFT + IND_PAD;
      const maxRx = DESK_RIGHT - IND_PAD - shapeW;
      return Math.max(minRx, Math.min(maxRx, rx));
    }

    /* ---------- top-left: ซ้าย→ขวา, step = shapeW จริง ---------- */
    if (zone === 'top-left') {
      let row = 0;
      let sx  = DESK_LEFT + IND_PAD;

      items.forEach((it, i) => {
        const size = getShapeSize(it);
        if (sx + size.w > rightBarrier && i > 0) {
          row++;
          sx = DESK_LEFT + IND_PAD;
        }
        const sy  = y0 + row * rowH;
        const rxC = clampX(sx, size.w);
        positions[i] = { sx: rxC, sy, row, shapeW: size.w, shapeH: size.h, shape: size.shape, cx: rxC + size.w / 2 };
        sx += size.w + IND_GAP;
      });
    }

    /* ---------- top-center: จากกลางออก ---------- */
else if (zone === 'top-center') {
  const cx0 = zd.x + zd.w / 2;

  /* แต่ละ item วางที่กึ่งกลาง แล้วลงแถวถัดไปเลย */
  items.forEach((it, i) => {
    const size = getShapeSize(it);
    const sx   = cx0 - size.w / 2;
    const sy   = y0 + i * rowH;
    const rxC  = clampX(sx, size.w);
    positions[i] = {
      sx: rxC, sy, row: i,
      shapeW: size.w, shapeH: size.h, shape: size.shape,
      cx: rxC + size.w / 2,
    };
  });
}

    /* ---------- top-right: ขวา→ซ้าย, step = shapeW จริง ---------- */
    else {
      let leftBarrier = DESK_LEFT + IND_PAD;
      if (allItems['top-center'].length > 0)
        leftBarrier = ZONE_DIVIDERS['top-right'].left + IND_PAD;
      else if (allItems['top-left'].length > 0)
        leftBarrier = ZONE_DIVIDERS['top-center'].left + IND_PAD;

      let row = 0;
      let sx  = DESK_RIGHT - IND_PAD; /* จะลบ shapeW ก่อนวาง */

      items.forEach((it, i) => {
        const size = getShapeSize(it);
        sx -= size.w; /* ขยับซ้ายก่อน */
        if (sx < leftBarrier && i > 0) {
          row++;
          sx = DESK_RIGHT - IND_PAD - size.w;
        }
        const sy  = y0 + row * rowH;
        const rxC = clampX(sx, size.w);
        positions[i] = { sx: rxC, sy, row, shapeW: size.w, shapeH: size.h, shape: size.shape, cx: rxC + size.w / 2 };
        sx -= IND_GAP; /* gap ก่อน item ถัดไป */
      });
    }

    /* ========== label Y ========== */
    const validPos  = positions.filter(Boolean);
    const maxRow    = validPos.length > 0 ? Math.max(...validPos.map(p => p.row)) : -1;
    const indBottom = maxRow >= 0
      ? (zd.y + IND_TOP) + (maxRow + 1) * IND_H + maxRow * IND_ROW_GAP
      : zd.y;

    if (lblEl) {
  lblEl.setAttribute('x', zd.x + zd.w / 2);
  lblEl.setAttribute('y', zd.y + zd.h / 2);  /* อยู่กึ่งกลาง zone เสมอ */
}

    /* ========== วาด shape + text ========== */
    items.forEach((item, i) => {
      if (!positions[i]) return;
      const { sx, sy, shapeW, shapeH, shape, cx } = positions[i];
      const cls = 'pp3-zi-rect'
        + (item.isExisting ? ' is-existing' : '')
        + (item.isCurrent  ? ' is-current'  : '');

      const cy = sy + IND_H / 2;  /* กึ่งกลาง Y ของ slot row */

      if (shape === 'circle') {
        indG.appendChild(svgEl('circle', {
          cx: cx,
          cy: cy,
          r:  shapeW / 2,
          class: cls,
        }));
      } else {
        indG.appendChild(svgEl('rect', {
          x:      sx,
          y:      cy - shapeH / 2,
          width:  shapeW,
          height: shapeH,
          rx: 2,
          class: cls,
        }));
      }

      /* text อยู่กึ่งกลาง shape จริง */
      indG.appendChild(svgEl('text', {
        x: cx,
        y: cy,
        class: 'pp3-zi-text' + (item.isExisting ? ' is-existing' : ''),
        'text-anchor':      'middle',
        'dominant-baseline':'central',
      }, item.label));
    });
  });
}

/* ================================================================
   GET EXISTING ITEMS — สำหรับ collision detection ใน offset
================================================================ */
function getExistingItems(zone, upToIdx) {
  const zd    = ZONE_DEF[zone];
  const items = [];
  const gs    = window.state;

  if (gs && gs.optConfig) {
    Object.entries(gs.optConfig).forEach(([key, arr]) => {
      const op = ((gs.meta && gs.meta.options) || []).find(o => o.key === key) || {};
      (arr || []).forEach(cfg => {
        if (cfg.place === zd.place && cfg.from === zd.from) {
          items.push({
            name: op.name || key,
            ox:   Number(cfg.offsetX) || MIN_X,
            w:    Number(cfg.w) || Number(op.defaultWcm) || 10,
          });
        }
      });
    });
  }

  S.done.slice(0, upToIdx).forEach(d => {
    if (d.zone === zone) {
      const op = S.opMeta || {};
      items.push({ name: op.name || S.optKey, ox: Number(d.ox) || MIN_X, w: Number(op.defaultWcm) || 10 });
    }
  });
  return items;
}

/* ================================================================
   GET EXISTING ITEMS CENTER — สำหรับคำนวณ offsetY ของ top-center
================================================================ */
function getExistingItemsCenter(upToIdx) {
  const items = [];
  const gs    = window.state;

  /* จาก optConfig ที่ save แล้ว */
  if (gs && gs.optConfig) {
    Object.entries(gs.optConfig).forEach(([key, arr]) => {
      const op = ((gs.meta && gs.meta.options) || []).find(o => o.key === key) || {};
      (arr || []).forEach(cfg => {
        if (cfg.place === 'center' && cfg.from === 'top') {
          items.push({
            name: op.name || key,
            oy:   Number(cfg.offsetY) || MIN_Y,
            h:    Number(cfg.h) || Number(op.defaultHcm) || 10,
          });
        }
      });
    });
  }

  /* จาก session done */
  S.done.slice(0, upToIdx).forEach(d => {
    if (d.zone === 'top-center') {
      const op = S.opMeta || {};
      items.push({
        name: op.name || S.optKey,
        oy:   Number(d.oy) || MIN_Y,
        h:    Number(op.defaultHcm) || 10,
      });
    }
  });

  return items;
}
/* ================================================================
   VALIDATE PLACEMENT
================================================================ */
function validatePlacement() {
  if (!S.zone) return;
  const valOx = S.zone === 'top-center' ? 0 : (parseFloat(oxInput.value) || 0);
  const valOy = parseFloat(oyInput.value) || 0;
  let warningMsg = null;
  let isError    = false;

  if (valOy < MIN_Y) {
    warningMsg = `ระยะห่างจากขอบบน ต้องไม่น้อยกว่า ${MIN_Y} cm`;
    isError = true;
  } else if (S.zone !== 'top-center' && valOx < MIN_X) {
    warningMsg = `ระยะห่างจากขอบซ้าย/ขวา ต้องไม่น้อยกว่า ${MIN_X} cm`;
    isError = true;
  }

  if (!isError && S.zone !== 'top-center') {
    const items = getExistingItems(S.zone, S.cur);
    const myW   = S.opMeta ? (Number(S.opMeta.defaultWcm) || 10) : 10;
    let overlapNames  = [];
    let recommendedOx = MIN_X;

    items.forEach(e => {
      const exStart = e.ox;
      const exEnd   = e.ox + e.w;
      const tStart  = valOx;
      const tEnd    = valOx + myW;
      if (!(tEnd <= exStart - GAP || tStart >= exEnd + GAP)) {
        if (!overlapNames.includes(e.name)) overlapNames.push(e.name);
      }
      recommendedOx = Math.max(recommendedOx, exEnd + GAP);
    });

    if (overlapNames.length > 0) {
      const dir      = S.zone === 'top-right' ? 'ขอบขวา' : 'ขอบซ้าย';
      const namesStr = overlapNames.map(n => `"${n}"`).join(', ');
      warningMsg = `ตำแหน่งทับซ้อนกับ ${namesStr} — แนะนำให้ขยับระยะห่างจาก${dir}เป็น ${Math.ceil(recommendedOx)} cm ขึ้นไป`;
      isError = true;
    }
  }

  if (isError) {
    warnEl.classList.add('show');
    warnTxt.textContent = warningMsg;
    okBtn.disabled = true;
  } else {
    warnEl.classList.remove('show');
    okBtn.disabled = false;
  }
}

/* ================================================================
   PICK ZONE
================================================================ */
function pickZone(zone) {
  S.zone = zone;
  zones.forEach(b => b.setAttribute('aria-pressed', b.dataset.zone === zone ? 'true' : 'false'));
  const zd = ZONE_DEF[zone];

  offEl.classList.add('show');

  if (zone === 'top-center') {
  boxOx.style.display = 'none';
  boxOy.style.display = 'flex';
  oyLbl.textContent   = 'จากขอบบน:';

  /* หา existing items ใน top-center ทั้งหมด (ไม่รวม current) */
  const centerItems = getExistingItemsCenter(S.cur);

  if (centerItems.length === 0) {
    /* อันแรก → ใช้ค่า default */
    oyInput.value = MIN_Y;
    infoAuto.classList.remove('show');
  } else {
    /* หา item สุดท้ายที่วางไว้ แล้วคำนวณ oy ถัดไป */
    const last   = centerItems[centerItems.length - 1];
    const safeOy = Math.ceil(last.oy + last.h + GAP);
    oyInput.value = safeOy;
    infoAuto.classList.add('show');
  }
	} else {
    boxOx.style.display = 'flex';
    boxOy.style.display = 'flex';

    const items = getExistingItems(zone, S.cur);
    const myW   = S.opMeta ? (Number(S.opMeta.defaultWcm) || 10) : 10;

    /* === First-Fit Gap Algorithm ===
       หาช่องว่างแรกจากขอบซ้าย (หรือขอบขวาสำหรับ top-right)
       ที่ item ใหม่ (กว้าง myW) วางได้โดยไม่ทับกับใคร (gap ≥ GAP cm)
       ถ้าไม่มีช่องว่างพอ → ต่อท้ายตามเดิม
    */
    let safeOx;
    if (items.length === 0) {
      safeOx = MIN_X;
    } else {
      /* เรียง items ตาม ox */
      const sorted = items.slice().sort((a, b) => a.ox - b.ox);

      /* ลองวางที่ MIN_X ก่อน */
      safeOx = null;
      const candidates = [MIN_X];
      /* เพิ่ม candidate หลังจบแต่ละ item */
      sorted.forEach(e => candidates.push(e.ox + e.w + GAP));

      for (const cOx of candidates) {
        if (cOx < MIN_X) continue;
        /* ตรวจว่าวางที่ cOx ได้โดยไม่ทับใคร */
        const tStart = cOx;
        const tEnd   = cOx + myW;
        const clash  = sorted.some(e => {
          const exStart = e.ox;
          const exEnd   = e.ox + e.w;
          return !(tEnd <= exStart - GAP || tStart >= exEnd + GAP);
        });
        if (!clash) { safeOx = cOx; break; }
      }

      /* ถ้าไม่มีช่อง → ต่อท้าย */
      if (safeOx === null) {
        safeOx = sorted.reduce((acc, e) => Math.max(acc, e.ox + e.w + GAP), MIN_X);
      }
    }
    safeOx = Math.ceil(safeOx);

    oxInput.value       = safeOx;
    oyInput.value       = Math.max(zd.defaultOy, MIN_Y);
    oxLbl.textContent   = zone === 'top-right' ? 'จากขอบขวา:' : 'จากขอบซ้าย:';
    oyLbl.textContent   = 'จากขอบบน:';

    if (safeOx > MIN_X) infoAuto.classList.add('show');
    else                 infoAuto.classList.remove('show');
  }

  okLbl.textContent = (S.cur < S.qty - 1) ? 'ยืนยัน — ชิ้นถัดไป' : 'ยืนยัน & เพิ่มลงโต๊ะ';
  renderAllIndicators();
  validatePlacement();
}

/* ================================================================
   OPEN MODAL
================================================================ */
function openPP(opts) {
  resetState(opts);

  const op   = S.opMeta || {};
  const imgs = String(op.imageUrl || '').split(',').map(s => s.trim()).filter(Boolean);
  let thumb  = imgs[0] || '';
  if (S.variant && Array.isArray(op.variants)) {
    const v = op.variants.find(vv => vv.name === S.variant);
    if (v && v.imageUrl) thumb = String(v.imageUrl).split(',')[0].trim();
  }
  imgEl.src           = thumb;
  nameEl.textContent  = op.name || S.optKey;
  dimEl.textContent   = [S.variant, op.defaultWcm ? `${op.defaultWcm}×${op.defaultHcm} cm` : ''].filter(Boolean).join(' · ') || '—';
  badgeEl.textContent = S.qty;

  if (S.qty > 1) { pieceEl.style.display = ''; pnEl.textContent = 1; ptEl.textContent = S.qty; }
  else             pieceEl.style.display = 'none';

  zones.forEach(b => b.setAttribute('aria-pressed', 'false'));
  okBtn.disabled = true;
  warnEl.classList.remove('show');
  infoAuto.classList.remove('show');
  offEl.classList.remove('show');

  renderAllIndicators();

  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  S.open = true;
}

/* ================================================================
   CLOSE MODAL
================================================================ */
function closePP() {
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  S.open = false;
}

/* ================================================================
   CONFIRM PIECE
================================================================ */
function confirmPiece() {
  const zone  = S.zone; if (!zone) return;
  const zd    = ZONE_DEF[zone];
  const valOx = zone === 'top-center' ? 0 : (parseFloat(oxInput.value) || 0);
  const valOy = parseFloat(oyInput.value) || 0;

  S.done.push({ zone, place: zd.place, from: zd.from, ox: valOx, oy: valOy });

  const next = S.cur + 1;
  if (next < S.qty) {
    S.cur  = next;
    S.zone = null;
    pnEl.textContent = next + 1;
    zones.forEach(b => b.setAttribute('aria-pressed', 'false'));
    okBtn.disabled = true;
    warnEl.classList.remove('show');
    infoAuto.classList.remove('show');
    offEl.classList.remove('show');
    renderAllIndicators();
  } else {
    const sel     = [...S.done];
    const origFn  = S.origFn;
    const cbDone  = S.cbDone;
    const optKey  = S.optKey;
    const variant = S.variant;
    closePP();
    if (typeof cbDone === 'function') cbDone(sel, origFn, optKey, variant);
  }
}

/* ================================================================
   EVENTS
================================================================ */
zones.forEach(btn => {
  btn.addEventListener('click', e => {
    const zone = btn.dataset.zone; if (!zone) return;
    const rip = document.createElement('div');
    rip.className  = 'pp3-rip';
    rip.style.left = e.clientX + 'px';
    rip.style.top  = e.clientY + 'px';
    document.body.appendChild(rip);
    setTimeout(() => rip.remove(), 600);
    pickZone(zone);
  });
  btn.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.click(); }
  });
});

oxInput.addEventListener('input', () => { validatePlacement(); renderAllIndicators(); });
oyInput.addEventListener('input', validatePlacement);
okBtn.addEventListener('click', confirmPiece);
backBtn.addEventListener('click', () => { const cb = S.cbBack; closePP(); if (typeof cb === 'function') cb(); });
xBtn.addEventListener('click', closePP);
bd.addEventListener('click', closePP);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && S.open) closePP(); });

let _tries = 0;
function installIntercept() {
  _tries++;
  const fn = window.addOptionWithVariantAndQty;
  if (typeof fn !== 'function') {
    if (_tries < 30) setTimeout(installIntercept, _tries < 10 ? 300 : 800);
    return;
  }
  if (fn.__pp3) return;
  const origFn = fn;

  window.addOptionWithVariantAndQty = function pp3_intercept(optKey, variantName, qty) {
    const gs = window.state;
    if (!gs) return origFn(optKey, variantName, qty);

    const op = ((gs.meta && gs.meta.options) || []).find(o => o.key === optKey) || {};
    const t  = String(op.type || '').toLowerCase();
    if (!HOLE_TYPES.includes(t)) return origFn(optKey, variantName, qty);

    openPP({
      optKey,
      variant: variantName || '',
      qty:     qty || 1,
      opMeta:  op,
      origFn:  origFn,
      cbBack: function() {
        const card = document.querySelector(`.dpb-opt-item[data-key="${CSS.escape(optKey)}"]`);
        if (card) card.click();
      },


      cbDone: function(selections, origFn2, oKey, oVariant) {

        /* --- helper: เซ็ต position บน entry โดย uid --- */
        function applyByUid(arr, uid, pos) {
          if (!arr || !uid) return;
          const ent = arr.find(e => e && e.uid === uid);
          if (ent) {
            ent.place   = pos.place;
            ent.from    = pos.from;
            ent.offsetX = pos.ox;
            ent.offsetY = pos.oy;
          }
        }

        /* --- helper: snapshot uid+position ของทุก entry ปัจจุบัน --- */
        function snapshotPositions(arr) {
          const map = new Map();
          (arr || []).forEach(e => {
            if (e && e.uid) {
              map.set(e.uid, {
                place:   e.place,
                from:    e.from,
                ox:      e.offsetX,
                oy:      e.offsetY,
              });
            }
          });
          return map;
        }

        /* 1. Snapshot ค่าเก่าทั้งหมดก่อน loop */
        const oldSnapshot = snapshotPositions(gs.optConfig[oKey]);

        /* uid ของ entry ใหม่แต่ละชิ้น (จะเก็บหลัง origFn2) */
        const newEntries = []; /* [{ uid, sel }] */

        selections.forEach(sel => {

          /* Snapshot ก่อน call เพื่อหา uid ใหม่ */
          const uidsBefore = new Set((gs.optConfig[oKey] || []).map(e => e && e.uid).filter(Boolean));

          origFn2(oKey, oVariant || '', 1);

          /* หา uid ใหม่ = uid ที่ไม่เคยมีก่อน */
          const arr = gs.optConfig[oKey] || [];
          let newUid = null;
          for (const e of arr) {
            if (e && e.uid && !uidsBefore.has(e.uid)) { newUid = e.uid; break; }
          }
          /* fallback: index สุดท้าย (push) */
          if (!newUid && arr[arr.length - 1]) newUid = arr[arr.length - 1].uid || null;

          newEntries.push({ uid: newUid, sel });

          /* เซ็ตทันทีหลัง origFn2 (ก่อน origFn2 ของชิ้นถัดไป) */
          applyByUid(arr, newUid, sel);

          /* Restore ค่าเก่าที่ origFn2 อาจ rebuild ทิ้ง */
          oldSnapshot.forEach((pos, uid) => applyByUid(arr, uid, pos));
        });

        /* 4. หลัง loop ทั้งหมด: เรียก buildOptConfig อีกครั้งเพื่อ render UI
              แล้ว restore ทุกค่าอีกรอบด้วย uid */
        if (typeof window.buildOptConfig === 'function') window.buildOptConfig();

        const arrFinal = gs.optConfig && gs.optConfig[oKey];
        newEntries.forEach(({ uid, sel }) => applyByUid(arrFinal, uid, sel));
        oldSnapshot.forEach((pos, uid) => applyByUid(arrFinal, uid, pos));

        if (typeof window.scheduleRedraw === 'function') window.scheduleRedraw();
			const pp3Thumb = document.getElementById('pp3-img');
		if (pp3Thumb && typeof window.flyBitmapToCart === 'function') {
			const r = pp3Thumb.getBoundingClientRect();
		if (r.width > 0 && r.height > 0) {
    window.flyBitmapToCart(pp3Thumb.currentSrc || pp3Thumb.src, r);
			}
		}
		
      }
    });
  };

  window.addOptionWithVariantAndQty.__pp3 = true;
  window.__dpbPP3 = { open: openPP, close: closePP };
}
setTimeout(installIntercept, 200);

})();
</script>


<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@500;600&display=swap');

    .goog-te-banner-frame,
    .goog-te-balloon-frame,
    #goog-gt-tt,
    .goog-tooltip,
    .goog-tooltip:hover {
        display: none !important;
    }
    body {
        top: 0 !important;
        position: static !important;
    }
    .skiptranslate {
        display: none !important;
    }

    .ds-header-lang-container {
        position: relative;
        display: inline-flex;
        align-items: center;
        background: rgba(20, 20, 20, 0.05);
        border: 1px solid rgba(0, 0, 0, 0.1);
        border-radius: 50px;
        padding: 4px;
        width: 100px;
        height: 36px;
        user-select: none;
        box-sizing: border-box;
    }

    @media (max-width: 768px) {
        .ds-header-lang-container {
            display: none !important;
        }
    }

    .ds-lang-slider {
        position: absolute;
        top: 4px;
        bottom: 4px;
        left: 4px;
        width: calc(50% - 4px);
        background: #ffffff;
        border-radius: 40px;
        transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        z-index: 1;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .ds-lang-btn {
        flex: 1;
        background: transparent !important;
        border: none !important;
        color: #888888;
        font-size: 13px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        position: relative;
        z-index: 2;
        padding: 0;
        text-align: center;
        transition: color 0.4s ease;
        outline: none;
        height: 100%;
        line-height: 28px;
    }

    .ds-lang-btn:hover { color: #555555; }

    .ds-header-lang-container[data-active="th"] .ds-lang-btn[data-lang="th"] { color: #111111; }
    .ds-header-lang-container[data-active="en"] .ds-lang-slider { transform: translateX(100%); }
    .ds-header-lang-container[data-active="en"] .ds-lang-btn[data-lang="en"] { color: #111111; }
</style>

<script>
(function () {
    'use strict';

    /* ─── URL Query String Helpers ─── */
    function getQueryLang() {
        var params = new URLSearchParams(window.location.search);
        return params.get('lang'); // 'en', 'th', หรือ null
    }

    function setQueryLang(lang) {
        var url = new URL(window.location.href);
        if (lang === 'th' || !lang) {
            url.searchParams.delete('lang');
        } else {
            url.searchParams.set('lang', lang);
        }
        return url.toString();
    }

    /* ─── Cookie Helpers ─── */
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }

    function clearGoogleTranslateCookies() {
        var host     = window.location.hostname;
        var hostNaked = host.replace(/^www\./, '');
        var domains  = [host, '.' + host, hostNaked, '.' + hostNaked];
        var paths    = ['/', '/configurator/', '/configurator'];
        domains.forEach(function (d) {
            paths.forEach(function (p) {
                document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=' + p + '; domain=' + d + ';';
            });
        });
        document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    }

    function setGoogleTranslateCookie(from, to) {
        var val       = '/' + from + '/' + to;
        var host      = window.location.hostname;
        var hostNaked = host.replace(/^www\./, '');
        document.cookie = 'googtrans=' + val + '; path=/; domain=' + host + ';';
        document.cookie = 'googtrans=' + val + '; path=/; domain=.' + hostNaked + ';';
        document.cookie = 'googtrans=' + val + '; path=/;';
    }

    /* ─── Browser Language ─── */
    function browserIsNonThai() {
        var lang = (navigator.language || navigator.userLanguage || 'th').substring(0, 2).toLowerCase();
        return lang !== 'th';
    }

    /* ─── ตรรกะกำหนดภาษาเริ่มต้น ───
       Priority: ?lang= query > browser language > default TH
    ─── */
    function getInitialLang() {
        var q = getQueryLang();
        if (q === 'en') return 'en';
        if (q === 'th') return 'th';
        // ไม่มี query → ดูภาษา browser
        if (browserIsNonThai()) return 'en';
        return 'th';
    }

    /* ─── Google Translate ─── */
    function loadGoogleTranslateScript(callback) {
        if (window.__dsGTLoaded) {
            if (callback) callback();
            return;
        }
        window.googleTranslateElementInit = function () {
            new google.translate.TranslateElement({
                pageLanguage: 'th',
                autoDisplay: false
            }, 'google_translate_element');
            window.__dsGTLoaded = true;
            if (callback) setTimeout(callback, 400);
        };
        var s = document.createElement('script');
        s.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
        s.async = true;
        document.head.appendChild(s);
    }

    function triggerGoogleTranslate(langCode) {
        var sel = document.querySelector('select.goog-te-combo');
        if (sel) {
            sel.value = langCode;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ─── Main ─── */
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('ds-header-lang-toggle');
        if (!container) return;

        var initLang = getInitialLang();
        container.setAttribute('data-active', initLang);

        if (initLang === 'en') {
            /* EN: set cookie แล้วโหลด GT */
            setGoogleTranslateCookie('th', 'en');
            loadGoogleTranslateScript(function () {
                triggerGoogleTranslate('en');
            });
        } else {
            /* TH: ล้าง cookie ให้สะอาด + preload GT เงียบๆ */
            clearGoogleTranslateCookies();
            window.googleTranslateElementInit = function () {
                new google.translate.TranslateElement({ pageLanguage: 'th', autoDisplay: false }, 'google_translate_element');
                window.__dsGTLoaded = true;
            };
            var s = document.createElement('script');
            s.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
            s.async = true;
            document.head.appendChild(s);
        }

        /* ─── Button Clicks ─── */
        container.querySelectorAll('.ds-lang-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target  = this.getAttribute('data-lang');
                var current = container.getAttribute('data-active');
                if (target === current) return;

                if (target === 'th') {
                    /*
                     * กลับ TH:
                     * 1. ล้าง cookie
                     * 2. เปลี่ยน URL → ลบ ?lang= ออก
                     * 3. Reload → ได้ content ต้นฉบับ 100%
                     */
                    clearGoogleTranslateCookies();
                    window.location.href = setQueryLang('th'); // ← URL ไม่มี ?lang= แล้ว

                } else {
                    /*
                     * ไปเป็น EN (หรือภาษาอื่น):
                     * 1. เปลี่ยน URL → ?lang=en
                     * 2. set cookie
                     * 3. Reload → Google Translate รับ cookie แล้วแปลทันที
                     */
                    setGoogleTranslateCookie('th', target);
                    window.location.href = setQueryLang(target); // ← URL มี ?lang=en
                }
            });
        });
    });
})();
</script>

	
<?php
  return ob_get_clean();
});