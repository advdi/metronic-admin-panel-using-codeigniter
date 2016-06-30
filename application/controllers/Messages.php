<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Messages extends MY_Controller {
    function __construct()
    {   
        parent::__construct();
        if (!$this->user) {
            $this->set_last_url();
            redirect('login');
        }
    }

	public function index()
	{
        $message_count = $this->check_message();
        $this->view_data['inbox'] = intval($message_count[0]->message_number);
        
        $message_count_important = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where important = TRUE');
        $this->view_data['important'] = $message_count_important[0]->message_number;

        $message_count_spam = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where spam = TRUE and deleted != TRUE');
        $this->view_data['spam'] = $message_count_spam[0]->message_number;


        $message_count_trash = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where status = "deleted"');
        $this->view_data['trash'] = intval($message_count_trash[0]->message_number); 
		
        $this->content_view = 'messages/list';
        //$this->session->set_flashdata('message', 'success: message!');
	}

function waga(){
            $this->load->helper('security');
            $this->load->helper('string');
            $this->theme_view = '';
            //print_r(explode("<", "Google <no-reply@accounts.google.com>"));
            //exit();
            $emailconfig = Setting::first();
            set_time_limit(0);
            // this shows basic IMAP, no TLS required
            $config['login'] = $emailconfig->mailbox_username;
            $config['pass'] = $emailconfig->mailbox_password;
            $config['host'] = $emailconfig->mailbox_host;
            $config['port'] = $emailconfig->mailbox_port;
            $config['mailbox'] = $emailconfig->mailbox_box;

            if($emailconfig->mailbox_imap == "1"){$flags = "/imap";}else{$flags = "/pop3";}
            if($emailconfig->mailbox_ssl == "1"){$flags .= "/ssl";}

            $config['service_flags'] = $flags.$emailconfig->mailbox_flags;

            $this->load->library('peeker', $config);
            //attachment folder
            $bool = $this->peeker->set_attachment_dir('files/media/email_files');
            //Search Filter
            $this->peeker->set_search($emailconfig->mailbox_search);
            echo $this->peeker->search_and_count_messages();
            
            if ($this->peeker->search_and_count_messages() != "0"){
                $id_array = $this->peeker->get_ids_from_search();
                
                //walk trough emails
                $details = array(); 

                  function reference(){
                        $str = do_hash(random_string('md5', 40), 'sha1'); // MD5
                        
                        $ref_id = Outbox_messages::find_by_view_id($str);  

                        if(!empty($ref_id)){
                            reference();
                        }
                        else{
                            return $str;
                        }
                    }               

                foreach($id_array as $email_id){
                    $email_object = $this->peeker->get_message($email_id);
                    $email_object->rewrite_html_transform_img_tags('files/media/email_files/');
                    $attachment = ($email_object->has_attachment())? TRUE: FALSE;
                    
                    if($attachment){
                        //Attachments
                        $parts = $email_object->get_parts_array();
                        $email_attachment = array();
                       
                        foreach ($parts as $part){
                            $savename = $email_object->get_fingerprint().random_string('alnum', 8).$part->get_filename();
                            $savename = str_replace(' ','_',$savename); $savename = str_replace('%20','_',$savename);
                            $savename = preg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '', $savename);
                            // Remove any runs of periods
                            $savename = preg_replace("([\.]{2,})", '', $savename);
                            $orgname = $part->get_filename();
                            $orgname = str_replace(' ','_',$orgname); $orgname = str_replace('%20','_',$orgname);
                            $part->filename = $savename;
                            $filetype = $part->get_subtype();
                            $size = $part->get_bytes();
                            $attributes = array('article_id' => $email_id, 'filename' => $orgname, 'filetype'=>$filetype, 'size'=>$size, 'savename' => $savename);
                            $attachment_temp = ArticleHasAttachment::create($attributes);
                            $email_attachment[] = "files/media/attachments/".$savename;
                        }
                        $email_object->save_all_attachments('files/media/attachments/');
                    }

                    $details['status'] = 'new';
                    $details['time'] = $email_object->get_date();
                    $details['recipient'] = $email_object->get_to();
                    $details['reply_to'] = $email_object->get_reply_to();
                    $details['sender'] = $email_object->get_from();
                    $details['cc'] = $email_object->get_cc();
                    $details['bcc'] = $email_object->get_bcc();
                    $details['subject'] = ($email_object->get_subject())? $email_object->get_subject(): '(no subject)';
                    
                    if ($email_object->has_PLAIN_not_HTML()) {
                        $details['message'] = nl2br($email_object->get_plain());    
                    } else {
                        $details['message'] = $email_object->get_html();    
                    }
                    
                    iconv(mb_detect_encoding($details['message'], mb_detect_order(), true), "UTF-8", $details['message']);
                    
                    $details['attachment'] = $attachment;
                    //$details['headers'] = $email_object->get_header_array();

                    $reference = reference();
                    $details['view_id'] = $reference;

                    $outbox = Outbox_messages::create($details);
                }
            }

            //print_r($details);
            $this->peeker->close();
            exit();
}

function mark(){
    $this->theme_view = '';

    if ($this->user && $this->input->is_ajax_request()) { 
         $message = Outbox_messages::find_by_view_id($this->input->get('message_id'));

         if ($message->important) {
             $message->important = FALSE;
             $reply['reply'] = "false";
         } else {
             $message->important = TRUE;
             $reply['reply'] = "true";
         }

         $message->save();

         $this->output->set_content_type('application/json')->set_output(json_encode($reply));
    }
}

function load_reply(){
            
         if ( $this->user && $this->input->is_ajax_request() ) {
             $this->theme_view = '';
             $message_array = Outbox_messages::find_by_view_id($this->input->get('message_id'));
             
             if(!$message_array){
                show_404();
             }

             $sender = explode("<", trim($message_array->sender));
             
             if (count($sender)  > 1 ) {
                 $sender_display = str_replace(">", '', $sender[1]);
             } else {
                 $sender_display = str_replace(">", '', $sender[0]);
             }
          $reply = '<form class="inbox-compose form-horizontal" id="fileupload" action="'.base_url().'messages/send_mail" method="POST" enctype="multipart/form-data">
            <div class="inbox-compose-btn">
                <button class="btn green">
                    <i class="fa fa-check"></i>Send</button>
                <button class="btn default">Discard</button>
                <button class="btn default">Draft</button>
            </div>
            <div class="inbox-form-group mail-to">
                <label class="control-label">To:</label>
                <div class="controls controls-to">
                    <input type="text" class="form-control" name="to" value="'.$sender_display.'">
                    <span class="inbox-cc-bcc">
                        <span class="inbox-cc " style="display:none"> Cc </span>
                        <span class="inbox-bcc"> Bcc </span>
                    </span>
                </div>
            </div>
            <div class="inbox-form-group input-cc display-hide">
                <a href="javascript:;" class="close"> </a>
                <label class="control-label">Cc:</label>
                <div class="controls controls-bcc">
                    <input type="text" name="cc" class="form-control"> </div>
            </div>
            <div class="inbox-form-group input-bcc display-hide">
                <a href="javascript:;" class="close"> </a>
                <label class="control-label">Bcc:</label>
                <div class="controls controls-bcc">
                    <input type="text" name="bcc" class="form-control"> </div>
            </div>
            <div class="inbox-form-group">
                <label class="control-label">Subject:</label>
                <div class="controls">
                    <input type="text" class="form-control" name="subject" value="'.$message_array->subject.'"> </div>
            </div>
            <div class="inbox-form-group">
                <div class="controls-row">
                    <textarea class="inbox-editor inbox-wysihtml5 form-control" name="message" rows="12"></textarea>
                    <!--blockquote content for reply message, the inner html of reply_email_content_body element will be appended into wysiwyg body. Please refer Inbox.js loadReply() function. -->
                    <div id="reply_email_content_body" class="hide">
                        <blockquote>'.$message_array->message.'</blockquote>
                    </div>
                </div>
            </div>
            <div class="inbox-compose-attachment">
                <!-- The fileupload-buttonbar contains buttons to add/delete files and start/cancel the upload -->
                <span class="btn green btn-outline  fileinput-button">
                    <i class="fa fa-plus"></i>
                    <span> Add files... </span>
                    <input type="file" name="files[]" multiple> </span>
                <!-- The table listing the files available for upload/download -->
                <table role="presentation" class="table table-striped margin-top-10">
                    <tbody class="files"> </tbody>
                </table>
            </div>
            <script id="template-upload" type="text/x-tmpl"> {% for (var i=0, file; file=o.files[i]; i++) { %}
                <tr class="template-upload fade">
                    <td class="name" width="30%">
                        <span>{%=file.name%}</span>
                    </td>
                    <td class="size" width="40%">
                        <span>{%=o.formatFileSize(file.size)%}</span>
                    </td> {% if (file.error) { %}
                    <td class="error" width="20%" colspan="2">
                        <span class="label label-danger">Error</span> {%=file.error%}</td> {% } else if (o.files.valid && !i) { %}
                    <td>
                        <p class="size">{%=o.formatFileSize(file.size)%}</p>
                        <div class="progress progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                            <div class="progress-bar progress-bar-success" style="width:0%;"></div>
                        </div>
                    </td> {% } else { %}
                    <td colspan="2"></td> {% } %}
                    <td class="cancel" width="10%" align="right">{% if (!i) { %}
                        <button class="btn btn-sm red cancel">
                            <i class="fa fa-ban"></i>
                            <span>Cancel</span>
                        </button> {% } %}</td>
                </tr> {% } %} </script>
            <!-- The template to display files available for download -->
            <script id="template-download" type="text/x-tmpl"> {% for (var i=0, file; file=o.files[i]; i++) { %}
                <tr class="template-download fade"> {% if (file.error) { %}
                    <td class="name" width="30%">
                        <span>{%=file.name%}</span>
                    </td>
                    <td class="size" width="40%">
                        <span>{%=o.formatFileSize(file.size)%}</span>
                    </td>
                    <td class="error" width="30%" colspan="2">
                        <span class="label label-danger">Error</span> {%=file.error%}</td> {% } else { %}
                    <td class="name" width="30%">
                        <a href="{%=file.url%}" title="{%=file.name%}" data-gallery="{%=file.thumbnail_url&&'. chr(39) . 'gallery' .chr(39) . '%}" download="{%=file.name%}">{%=file.name%}</a>
                    </td>
                    <td class="size" width="40%">
                        <span>{%=o.formatFileSize(file.size)%}</span>
                    </td>
                    <td colspan="2"></td> {% } %}
                    <td class="delete" width="10%" align="right">
                        <button class="btn default btn-sm" data-type="{%=file.delete_type%}" data-url="{%=file.delete_url%}" {% if (file.delete_with_credentials) { %} data-xhr-fields='.chr(39).'{"withCredentials":true}'.chr(39).'{% } %}>
                            <i class="fa fa-times"></i>
                        </button>
                    </td>
                </tr> {% } %} </script>
            <div class="inbox-compose-btn">
                <button class="btn green">
                    <i class="fa fa-check"></i>Send</button>
                <button class="btn default">Discard</button>
                <button class="btn default">Draft</button>
            </div>
        </form>';

        $this->output->set_content_type('text/html')->set_output($reply);
    }
}

function bulk_action($mode = NULL){
    $this->theme_view = '';

    if ($this->user && $this->input->is_ajax_request()) {
        switch ($mode) {
            case 'read':
                foreach ($this->input->post('mail_ids') as $key => $value) {
                      $message_array = Outbox_messages::find_by_view_id($value);
                      $message_array->status = "read";
                      $message_array->save();
                }
                
                $reply = array();
                $reply["token_name"] = $this->security->get_csrf_token_name();
                $reply["token"] = $this->security->get_csrf_hash();
                $this->output->set_content_type('application/json')->set_output(json_encode($reply));
                break;

            case 'unread':
                foreach ($this->input->post('mail_ids') as $key => $value) {
                      $message_array = Outbox_messages::find_by_view_id($value);
                      $message_array->status = "new";
                      $message_array->save();
                }
                
                $reply = array();
                $reply["token_name"] = $this->security->get_csrf_token_name();
                $reply["token"] = $this->security->get_csrf_hash();
                $this->output->set_content_type('application/json')->set_output(json_encode($reply));
                break;

            case 'spam':
                foreach ($this->input->post('mail_ids') as $key => $value) {
                      $message_array = Outbox_messages::find_by_view_id($value);
                      $message_array->spam = TRUE;
                      $message_array->save();
                }
                
                $reply = array();
                $reply["token_name"] = $this->security->get_csrf_token_name();
                $reply["token"] = $this->security->get_csrf_hash();
                $this->output->set_content_type('application/json')->set_output(json_encode($reply));
                break;

            case 'important':
                foreach ($this->input->post('mail_ids') as $key => $value) {
                      $message_array = Outbox_messages::find_by_view_id($value);
                      $message_array->important = TRUE;
                      $message_array->save();
                }
                
                $reply = array();
                $reply["token_name"] = $this->security->get_csrf_token_name();
                $reply["token"] = $this->security->get_csrf_hash();
                $this->output->set_content_type('application/json')->set_output(json_encode($reply));
                break;

            case 'delete':
                foreach ($this->input->post('mail_ids') as $key => $value) {
                      $message_array = Outbox_messages::find_by_view_id($value);
                      $message_array->deleted = TRUE;
                      $message_array->save();
                }
                
                $reply = array();
                $reply["token_name"] = $this->security->get_csrf_token_name();
                $reply["token"] = $this->security->get_csrf_hash();
                $this->output->set_content_type('application/json')->set_output(json_encode($reply));
                break;
            
            default:
                # code...
                break;
        }
    } else {
        show_404();
    }
}

function load_message_list($mode = NULL){
    if ( $this->input->is_ajax_request() && $this->user) {
            
            if($this->input->get('filter')){
                $filter = intval($this->input->get('filter'));
            } else {
                $filter = 10;
            }   

            $this->theme_view = '';

            switch ($mode) {
                case 'inbox':
                    $message_count = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where deleted != TRUE and spam != TRUE');

                    $iTotalRecords = intval($message_count[0]->message_number);
                    $iDisplayLength = $filter;
                    $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength; 
                    $iDisplayStart = ($this->input->get('start'))? $this->input->get('start') : 0;

                    $end = $iDisplayStart + $iDisplayLength;
                    $end = $end > $iTotalRecords ? $iTotalRecords : $end;

                    $message_array = Outbox_messages::find('all', array('limit'=>$iDisplayLength,'offset'=>$iDisplayStart,'conditions'=> array(' deleted != ? and spam != ?', TRUE, TRUE)));
                    break;

                case 'important':
                    $message_count = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where important = TRUE and deleted != TRUE and spam != TRUE');

                    $iTotalRecords = intval($message_count[0]->message_number);
                    $iDisplayLength = $filter;
                    $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength; 
                    $iDisplayStart = 0;

                    $end = $iDisplayStart + $iDisplayLength;
                    $end = $end > $iTotalRecords ? $iTotalRecords : $end;

                    $message_array = Outbox_messages::find('all', array('limit'=>$iDisplayLength,'offset'=>$iDisplayStart,'conditions'=> array(' important = ? and deleted != ? and spam != ?', TRUE,TRUE,TRUE)));
                    break;

                case 'spam':
                    $message_count = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where spam = TRUE and deleted != TRUE');

                    $iTotalRecords = intval($message_count[0]->message_number);
                    $iDisplayLength = $filter;
                    $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength; 
                    $iDisplayStart = 0;

                    $end = $iDisplayStart + $iDisplayLength;
                    $end = $end > $iTotalRecords ? $iTotalRecords : $end;

                    $message_array = Outbox_messages::find('all', array('limit'=>$iDisplayLength,'offset'=>$iDisplayStart,'conditions'=> array(' spam = ? and deleted != ?', TRUE, TRUE)));
                    break;

                case 'trash':
                    $message_count = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where deleted = TRUE and spam != TRUE');

                    $iTotalRecords = intval($message_count[0]->message_number);
                    $iDisplayLength = $filter;
                    $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength; 
                    $iDisplayStart = 0;

                    $end = $iDisplayStart + $iDisplayLength;
                    $end = $end > $iTotalRecords ? $iTotalRecords : $end;

                    $message_array = Outbox_messages::find('all', array('limit'=>$iDisplayLength,'offset'=>$iDisplayStart,'conditions'=> array(' deleted = ? and spam != ? ', TRUE, TRUE)));
                    break;
                
                default:
                    # code...
                    break;
            }
            

            //$display_counter = ($message_count[0]->message_number > 0) ? 1 . " - " . $filter . " of ". $message_count[0]->message_number : NULL;
            
            

            $wrapper = array();

            if ($message_array) {
                 foreach($message_array as $key => $value){
                            $star = ($value->important)? " inbox-started": NULL;
                            $status = ($value->status == "new")? 'unread': NULL;
                            $marker = ($value->status == "new")? '<i class="fa fa-envelope"></i> &nbsp;': '<i class="icon-envelope-open"></i> &nbsp;';
                            $attachment = ($value->attachment)? '<i class="fa fa-paperclip"></i>': NULL;
                            
                            $sender = explode("<", trim($value->sender));
         
                             if (count($sender)  > 1 ) {
                                 $sender_display = $sender[0];
                             } else {
                                 $sender_display = $sender[0];
                             }

                    $wrapper['data'][] = array( 
                    
                             '<tr class="' . $status . ' col-message" data-messageid="'.$value->view_id.'"><td class="inbox-small-cells"><label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input type="checkbox" class="mail-checkbox" name="mails[]" value="'.$value->view_id.'" /><span></span></label></td>', '<td class="inbox-small-cells"><i class="fa fa-star '. $star .' star-marker" data-id="'.$value->view_id.'"></i></td>', 
                             '<td class="view-message hidden-xs">'. $marker . $sender_display . '</td>','<td class="view-message ">'. $value->subject .'</td>', '<td class="view-message inbox-small-cells">'. $attachment .'</td> <td class="view-message"  style="width: 130px;">'. date_format(date_create($value->time), "M. d, Y H:i:s a") .'</td></tr>'
                    );

                }
                 
            } else {
                 $messages ='<tr><td class="text-center" colspan="4"><code>No messages found.</code></td></tr>';

            }

         $this->output->set_content_type('application/json')->set_output(json_encode($wrapper));

    } else {
        show_404();
    }
}

function view_message($id = NULL){
        
        if ( $this->user && $this->input->is_ajax_request() ) {
             $this->theme_view = '';
             $message_array = Outbox_messages::find_by_view_id($this->input->get('message_id'));
             
             if($message_array){
                  $message_array->status = "read";
                  $message_array->save();

             } else {
                show_404();
             }

             $sender = explode("<", trim($message_array->sender));
             
             if (count($sender)  > 1 ) {
                 $sender_display = '<span class="sbold">' . $sender[0] . '</span> <span> &#60;'. $sender[1] .'</span>';
             } else {
                 $sender_display = '<span class="sbold">' . $sender[0] . '</span>';
             }

             $cc = ($message_array->cc)? '<br /><span> Cc: <i class="fa fa-angle-double-right"></i> ' . $message_array->cc .'</span>': NULL;

             $return_this = '<div class="inbox-header inbox-view-header">
                            <h1 class="pull-left">'. $message_array->subject . '</h1>
                        </div>
                        <div class="inbox-view-info">
                            <div class="row">
                                <div class="col-md-7">
                                    To: <i class="fa fa-angle-double-right"></i>
                                    <span class="sbold">' . $message_array->recipient . '</span> <br />
                                    From: <i class="fa fa-angle-double-right"></i> ' . $sender_display . ' <br /> On: <i class="fa fa-angle-double-right"></i> '. date_format(date_create($message_array->time), "M. d, Y h:i:s a") .'
                                    '. $cc .'

                                </div>
                                <div class="col-md-5 inbox-info-btn">
                                    <div class="btn-group">
                                        <a class="btn btn-sm blue btn-outline dropdown-toggle sbold" href="javascript:;" data-toggle="dropdown"> Actions
                                            <i class="fa fa-angle-down"></i>
                                        </a>

                                        <ul class="dropdown-menu pull-right">
                                            <li>
                                                <a href="javascript:;" data-messageid="'.$message_array->view_id.'" class="reply-btn">
                                                    <i class="fa fa-reply"></i> Reply </a>
                                            </li>
                                            <li>
                                                <a href="javascript:;">
                                                    <i class="fa fa-arrow-right reply-btn"></i> Forward </a>
                                            </li>
                            
                                            <li class="divider"> </li>
                                            <li>
                                                <a href="javascript:;">
                                                    <i class="fa fa-ban"></i> Spam </a>
                                            </li>
                                            <li>
                                                <a href="javascript:;">
                                                    <i class="fa fa-trash-o"></i> Delete </a>
                                            </li>
                                         </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="inbox-view">'
                         . $message_array->message .   
                        '</div>';
                        
                        if ($message_array->attachment) {
                            $attachment_array = ArticleHasAttachment::find('all', array('conditions'=> array(' article_id = ?', $message_array->id)));

                              function formatSizeUnits($bytes)
                                    {
                                        if ($bytes >= 1073741824)
                                        {
                                            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
                                        }
                                        elseif ($bytes >= 1048576)
                                        {
                                            $bytes = number_format($bytes / 1048576, 2) . ' MB';
                                        }
                                        elseif ($bytes >= 1024)
                                        {
                                            $bytes = number_format($bytes / 1024, 2) . ' KB';
                                        }
                                        elseif ($bytes > 1)
                                        {
                                            $bytes = $bytes . ' bytes';
                                        }
                                        elseif ($bytes == 1)
                                        {
                                            $bytes = $bytes . ' byte';
                                        }
                                        else
                                        {
                                            $bytes = '0 bytes';
                                        }

                                        return $bytes;
                                }

                            $files = '';
                            foreach ($attachment_array as $key => $value) {
                                $size = formatSizeUnits(intval($value->size));
                                $types = array('png', 'jpg', 'gif', 'bmp');

                                if (in_array($value->filetype, $types)) {
                                    $files = '<div class="margin-bottom-25">
                                            <img src="'.base_url().'files/media/attachments/'.$value->savename.'">
                                          </div>
                                          <div>
                                                <strong>File name: '.$value->filename.'</strong>
                                                <span>'.$size.'</span>
                                                <a href="javascript:;">View </a>
                                                <a href="javascript:;">Download </a>
                                         '.$files.'</div>';
                                } else {
                                    $files = '<div class="margin-bottom-25"></div>
                                                <div>
                                                    <strong>File name: '.$value->filename.'</strong>
                                                    <span>'.$size.'</span>
                                                    <a href="javascript:;">View </a>
                                                    <a href="javascript:;">Download </a>
                                             '.$files.'</div>';
                                }
                            }

                            $return_this = $return_this . '<hr>
                                            <div class="inbox-attached">
                                                <div class="margin-bottom-15">
                                                    <span>attachments — </span>
                                                    <a href="javascript:;">Download all attachments </a>
                                                </div>'.$files.'</div>';
                        }

            $this->output->set_content_type('text/html')->set_output($return_this);
    } else {
        show_404();
    }
}

function check_counters(){
    $this->theme_view = '';
    $return_data = array();

    if ( $this->user && $this->input->is_ajax_request() ) {
        
        $message_array = Outbox_messages::find('all', array('conditions'=> array(' status = ? and deleted != ? and spam != ?', "new",TRUE, TRUE)));
        
        $messages = '';

        if ($message_array) {
                 foreach($message_array as $key => $value){
                      $unix = human_to_unix(date_format(date_create($value->time),'Y-m-d G:i')); 
                      $moment = time_ago($unix, false);

                      $messages =  '<li>
                            <a href="'.base_url().'messages?a=view&msg='.$value->view_id.'">
                                <span class="photo">
                                    <img src="' . base_url() . 'files/media/avatars/no-pic.png" class="img-circle" alt=""> </span>
                                <span class="subject">
                                    <span class="from">' . character_limiter($value->subject, 20) . '</span>
                                    <span class="time">' . $moment . '</span>
                                </span>
                                <span class="message">'. character_limiter(strip_tags($value->message), 20) . '</span>
                            </a>
                        </li>'. $messages;
                }
                 
        }

        $return_data['peek'] = $messages;

        $message_count_inbox = $this->check_message();
        $return_data['inbox'] = $message_count_inbox[0]->message_number;

        $message_count_inbox_all = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where deleted != TRUE and spam != TRUE');
        $return_data['inbox_all'] = $message_count_inbox_all[0]->message_number;

        $message_count_trash = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where deleted = TRUE');
        $return_data['trash'] = $message_count_trash[0]->message_number;

        $message_count_important = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where important = TRUE');
        $return_data['important'] = $message_count_important[0]->message_number;

        $message_count_spam = Outbox_messages::find_by_sql('select count(id) as message_number from outbox_messages where spam = TRUE and deleted != TRUE');
        $return_data['spam'] = $message_count_spam[0]->message_number;

        $this->output->set_content_type('application/json')->set_output(json_encode($return_data));

    } else {
        show_404();
    }
    
}

function send_mail(){

    if ($this->user) {
        $this->load->library('email');

       $result = $this->email
                ->from($this->user->email)
                ->to($this->input->post('to'))
                ->subject($this->input->post('subject'))
                ->message($this->input->post('message'));

        if ($this->input->post('cc')) {
            $this->email->cc($this->input->post('cc'));
        }

        if ($this->input->post('bcc')) {
            $this->email->bcc($this->input->post('bcc'));
        }
                
        if ($result->send()) {
            $error = array('success: Email sent');
            $this->session->set_flashdata('message', $error);

        } else {
            $error = array('error: something went wrong.');
            $this->session->set_flashdata('message', $error);
        }
                            
        redirect($this->agent->referrer());
    }       
}

function load_composer(){
    if ( $this->input->is_ajax_request() && $this->user) {
        $this->theme_view = '';

        $data = form_open_multipart('messages/send_mail','class="inbox-compose form-horizontal" id="fileupload"').'
                <div class="inbox-compose-btn">
                    <button class="btn green">
                        <i class="fa fa-check"></i>Send</button>
                    <button class="btn default inbox-discard-btn">Discard</button>
                    <button class="btn default">Draft</button>
                </div>
                <div class="inbox-form-group mail-to">
                    <label class="control-label">To:</label>
                    <div class="controls controls-to">
                        <input type="text" class="form-control" name="to">
                        <span class="inbox-cc-bcc">
                            <span class="inbox-cc"> Cc </span>
                            <span class="inbox-bcc"> Bcc </span>
                        </span>
                    </div>
                </div>
                <div class="inbox-form-group input-cc display-hide">
                    <a href="javascript:;" class="close"> </a>
                    <label class="control-label">Cc:</label>
                    <div class="controls controls-cc">
                        <input type="text" name="cc" class="form-control"> </div>
                </div>
                <div class="inbox-form-group input-bcc display-hide">
                    <a href="javascript:;" class="close"> </a>
                    <label class="control-label">Bcc:</label>
                    <div class="controls controls-bcc">
                        <input type="text" name="bcc" class="form-control"> </div>
                </div>
                <div class="inbox-form-group">
                    <label class="control-label">Subject:</label>
                    <div class="controls">
                        <input type="text" class="form-control" name="subject"> </div>
                </div>
                <div class="inbox-form-group">
                    <textarea class="inbox-editor inbox-wysihtml5 form-control" name="message" rows="12"></textarea>
                </div>
                <div class="inbox-compose-attachment">
                    <!-- The fileupload-buttonbar contains buttons to add/delete files and start/cancel the upload -->
                    <span class="btn green btn-outline fileinput-button">
                        <i class="fa fa-plus"></i>
                        <span> Add files... </span>
                        <input type="file" name="files[]" multiple> </span>
                    <!-- The table listing the files available for upload/download -->
                    <table role="presentation" class="table table-striped margin-top-10">
                        <tbody class="files"> </tbody>
                    </table>
                </div>
                <script id="template-upload" type="text/x-tmpl"> {% for (var i=0, file; file=o.files[i]; i++) { %}
                    <tr class="template-upload fade">
                        <td class="name" width="30%">
                            <span>{%=file.name%}</span>
                        </td>
                        <td class="size" width="40%">
                            <span>{%=o.formatFileSize(file.size)%}</span>
                        </td> {% if (file.error) { %}
                        <td class="error" width="20%" colspan="2">
                            <span class="label label-danger">Error</span> {%=file.error%}</td> {% } else if (o.files.valid && !i) { %}
                        <td>
                            <p class="size">{%=o.formatFileSize(file.size)%}</p>
                            <div class="progress progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <div class="progress-bar progress-bar-success" style="width:0%;"></div>
                            </div>
                        </td> {% } else { %}
                        <td colspan="2"></td> {% } %}
                        <td class="cancel" width="10%" align="right">{% if (!i) { %}
                            <button class="btn btn-sm red cancel">
                                <i class="fa fa-ban"></i>
                                <span>Cancel</span>
                            </button> {% } %}</td>
                    </tr> {% } %} </script>
                <!-- The template to display files available for download -->
                <script id="template-download" type="text/x-tmpl"> {% for (var i=0, file; file=o.files[i]; i++) { %}
                    <tr class="template-download fade"> {% if (file.error) { %}
                        <td class="name" width="30%">
                            <span>{%=file.name%}</span>
                        </td>
                        <td class="size" width="40%">
                            <span>{%=o.formatFileSize(file.size)%}</span>
                        </td>
                        <td class="error" width="30%" colspan="2">
                            <span class="label label-danger">Error</span> {%=file.error%}</td> {% } else { %}
                        <td class="name" width="30%">
                            <a href="{%=file.url%}" title="{%=file.name%}" data-gallery="{%=file.thumbnail_url&&'.chr(39).'gallery'.chr(39).'%}" download="{%=file.name%}">{%=file.name%}</a>
                        </td>
                        <td class="size" width="40%">
                            <span>{%=o.formatFileSize(file.size)%}</span>
                        </td>
                        <td colspan="2"></td> {% } %}
                        <td class="delete" width="10%" align="right">
                            <button class="btn default btn-sm" data-type="{%=file.delete_type%}" data-url="{%=file.delete_url%}" {% if (file.delete_with_credentials) { %} data-xhr-fields='.chr(39).'{"withCredentials":true}'.chr(39).' {% } %}>
                                <i class="fa fa-times"></i>
                            </button>
                        </td>
                    </tr> {% } %} </script>
                <div class="inbox-compose-btn">
                    <button class="btn green">
                        <i class="fa fa-check"></i>Send</button>
                    <button class="btn default">Discard</button>
                    <button class="btn default">Draft</button>
                </div>
            </form>';
         $this->output->set_content_type('text/html')->set_output($data);
    } else {
        show_404();
    }


}



}
