<?php
/**
 * Copyright (C) 2007,2008,2009,2010  Arie Nugraha (dicarve@yahoo.com)
 * Modified for News/Information Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* Information/News Management section */

// key to authenticate
if (!defined('INDEX_AUTH')) {
    define('INDEX_AUTH', '1');
}

use SLiMS\AlLibrarian;

// key to get full database access
define('DB_ACCESS', 'fa');

if (!defined('SB')) {
    // main system configuration
    require '../../../sysconfig.inc.php';
    ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
    // start the session
    require SB . 'admin/default/session.inc.php';
}
// IP based access limitation
// require LIB . 'ip_based_access.inc.php';
// do_checkIP('smc');
// do_checkIP('smc-informasi');

require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
require SIMBIO . 'simbio_FILE/simbio_file_upload.inc.php';

// privileges checking
$can_read = utility::havePrivilege('informasi', 'r');
$can_write = utility::havePrivilege('informasi', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

// execute registered hook
\SLiMS\Plugins::getInstance()->execute('informasi_init');

// load settings
utility::loadSettings($dbs);

$in_pop_up = false;
// check if we are inside pop-up window
if (isset($_GET['inPopUp'])) {
    $in_pop_up = true;
}

if (!function_exists('getimagesizefromstring')) {
    function getimagesizefromstring($string_data)
    {
        $uri = 'data://application/octet-stream;base64,' . base64_encode($string_data);
        return getimagesize($uri);
    }
}

/* REMOVE IMAGE */
if (isset($_POST['removeImage']) && isset($_POST['nimg']) && isset($_POST['img'])) {
    // validate post image
    $news_id = utility::filterData('nimg', 'post', true, true, true);
    $image_name = utility::filterData('img', 'post', true, true, true);

    $query_image = $dbs->query("SELECT id FROM informasi WHERE id='{$news_id}' AND gambar='{$image_name}'");
    if ($query_image->num_rows > 0) {
        $_delete = $dbs->query(sprintf('UPDATE informasi SET gambar=NULL WHERE id=%d', $news_id));
        if ($_delete) {
            $postImage = stripslashes($_POST['img']);
            $postImage = str_replace('/', '', $postImage);
            @unlink(sprintf(IMGBS . 'docs/%s', $postImage));
            utility::jsToastr('Informasi', str_replace('{imageFilename}', $_POST['img'], __('{imageFilename} successfully removed!')), 'success');
            exit('<img src="../lib/minigalnano/createthumb.php?filename=images/default/image.png&width=130" class="img-fluid rounded" alt="">');
        }
    }
    exit();
}

/* RECORD OPERATION */
if (isset($_POST['saveData']) AND $can_read AND $can_write) {
    if (!simbio_form_maker::isTokenValid()) {
        utility::jsToastr('Informasi', __('Invalid form submission token!'), 'error');
        utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'system', 'Invalid form submission token, might be a CSRF attack from ' . $_SERVER['REMOTE_ADDR']);
        exit();
    }
    
    $judul = trim(strip_tags($_POST['judul']));
    // check form validity
    if (empty($judul)) {
        utility::jsToastr('Informasi', __('Judul tidak boleh kosong'), 'error');
        exit();
    } else {
        $data['judul'] = $dbs->escape_string($judul);
        $data['isi'] = $dbs->escape_string($_POST['isi']);
        $data['status'] = $_POST['status'];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        // image uploading
        if (!empty($_FILES['gambar']) AND $_FILES['gambar']['size']) {
            // create upload object
            $image_upload = new simbio_file_upload();
            $image_upload->setAllowableFormat($sysconf['allowed_images']);
            $image_upload->setMaxSize($sysconf['max_image_upload'] * 1024);
            $image_upload->setUploadDir(IMGBS . 'docs');
            // upload the file and change all space characters to underscore
            $img_upload_status = $image_upload->doUpload('gambar', preg_replace('@\s+@i', '_', $_FILES['gambar']['name']));
            if ($img_upload_status == UPLOAD_SUCCESS) {
                $data['gambar'] = $dbs->escape_string($image_upload->new_filename);
                // write log
                utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'informasi', $_SESSION['realname'] . ' upload image file ' . $image_upload->new_filename);
                utility::jsToastr('Informasi', __('Image Uploaded Successfully'), 'success');
            } else {
                // write log
                utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'informasi', 'ERROR : ' . $_SESSION['realname'] . ' FAILED TO upload image file ' . $image_upload->new_filename . ', with error (' . $image_upload->error . ')');
                utility::jsToastr('Informasi', __('Image Uploaded Failed'), 'error');
            }
        } else if (!empty($_POST['base64picstring'])) {
            list($filedata, $filedom) = explode('#image/type#', $_POST['base64picstring']);
            $filedata = base64_decode($filedata);
            $fileinfo = getimagesizefromstring($filedata);
            $valid = strlen($filedata) / 1024 < $sysconf['max_image_upload'];
            $valid = (!$fileinfo || $valid === false) ? false : in_array($fileinfo['mime'], $sysconf['allowed_images_mimetype']);
            $new_filename = strtolower('news_'
                . preg_replace("/[^a-zA-Z0-9]+/", "_", $data['judul'])
                . '.' . $filedom);

            if ($valid AND file_put_contents(IMGBS . 'docs/' . $new_filename, $filedata)) {
                $data['gambar'] = $dbs->escape_string($new_filename);
                if (!defined('UPLOAD_SUCCESS')) define('UPLOAD_SUCCESS', 1);
                $upload_status = UPLOAD_SUCCESS;
            }
        }

        // create sql op object
        $sql_op = new simbio_dbop($dbs);
        
        if (isset($_POST['updateRecordID'])) {
            /* UPDATE RECORD MODE */
            // remove created_at
            unset($data['created_at']);
            // filter update record ID
            $updateRecordID = (integer)$_POST['updateRecordID'];
            \SLiMS\Plugins::getInstance()->execute('informasi_before_update', ['data' => array_merge($data, ['id' => $updateRecordID])]);
            // update data
            $update = $sql_op->update('informasi', $data, 'id=' . $updateRecordID);
            // send an alert
            if ($update) {
                // execute registered hook
                \SLiMS\Plugins::getInstance()->execute('informasi_after_update', ['data' => array_merge($data, ['id' => $updateRecordID])]);

                utility::jsToastr('Informasi', __('News Data Successfully Updated'), 'success');
                // write log
                utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'informasi', $_SESSION['realname'] . ' update news data (' . $data['judul'] . ') with id (' . $updateRecordID . ')');

                // close window OR redirect main page
                if ($in_pop_up) {
                    echo '<script type="text/javascript">top.$(\'#mainContent\').simbioAJAX(parent.jQuery.ajaxHistory[0].url, {method: \'post\'});</script>';
                    echo '<script type="text/javascript">top.closeHTMLpop();</script>';
                } else {
                    echo '<script type="text/javascript">top.$(\'#mainContent\').simbioAJAX(parent.jQuery.ajaxHistory[0].url);</script>';
                }
            } else {
                utility::jsToastr('Informasi', __('News Data FAILED to Updated. Please Contact System Administrator') . "\n" . $sql_op->error, 'error');
            }
        } else {
            // execute registered hook
            \SLiMS\Plugins::getInstance()->execute('informasi_before_save', ['data' => $data]);

            /* INSERT RECORD MODE */
            // insert the data
            $insert = $sql_op->insert('informasi', $data);
            if ($insert) {
                // get auto id of this record
                $last_news_id = $sql_op->insert_id;

                // execute registered hook
                $data['id'] = $last_news_id;
                \SLiMS\Plugins::getInstance()->execute('informasi_after_save', ['data' => $data]);

                utility::jsToastr('Informasi', __('New News Data Successfully Saved'), 'success');
                // write log
                utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'informasi', $_SESSION['realname'] . ' insert news data (' . $data['judul'] . ') with id (' . $last_news_id . ')');
            } else {
                utility::jsToastr('Informasi', __('News Data FAILED to Save. Please Contact System Administrator') . "\n" . $sql_op->error, 'error');
            }
        }

        echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\'' . MWB . 'informasi/index.php\', {method: \'post\'});</script>';
        exit();
    }
    exit();
} else if (isset($_POST['itemID']) AND !empty($_POST['itemID']) AND isset($_POST['itemAction'])) {
    if (!($can_read AND $can_write)) {
        die();
    }
    if (!simbio_form_maker::isTokenValid()) {
        utility::jsToastr('Informasi', __('Invalid form submission token!'), 'error');
        utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'system', 'Invalid form submission token, might be a CSRF attack from ' . $_SERVER['REMOTE_ADDR']);
        exit();
    }
    /* DATA DELETION PROCESS */
    // create sql op object
    $sql_op = new simbio_dbop($dbs);
    $error_num = 0;
    
    if (!is_array($_POST['itemID'])) {
        // make an array
        $_POST['itemID'] = array((integer)$_POST['itemID']);
    }
    
    // loop array
    foreach ($_POST['itemID'] as $itemID) {
        $itemID = (integer)$itemID;
        
        // get news title for logging
        $_sql_news_q = sprintf('SELECT judul FROM informasi WHERE id=%d', $itemID);
        $news_q = $dbs->query($_sql_news_q);
        $news_d = $news_q->fetch_row();
        
        if (!$sql_op->delete('informasi', "id=$itemID")) {
            $error_num++;
        } else {
            // execute registered hook
            \SLiMS\Plugins::getInstance()->execute('informasi_on_delete', [$itemID]);
            // write log
            utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'informasi', $_SESSION['realname'] . ' DELETE news data (' . $news_d[0] . ') with id (' . $itemID . ')');
        }
    }
    
    // error alerting
    if ($error_num == 0) {
        utility::jsToastr('Informasi', __('All Data Successfully Deleted'), 'success');
        echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\'' . $_SERVER['PHP_SELF'] . '\', {addData: \'' . $_POST['lastQueryStr'] . '\'});</script>';
    } else {
        utility::jsToastr('Informasi', __('Some or All Data NOT deleted successfully!\nPlease contact system administrator'), 'warning');
        echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\'' . $_SERVER['PHP_SELF'] . '\', {addData: \'' . $_POST['lastQueryStr'] . '\'});</script>';
    }
    exit();
}
/* RECORD OPERATION END */

if (!$in_pop_up) {
    /* search form */
    ?>
    <div class="menuBox">
        <div class="menuBoxInner biblioIcon">
            <div class="per_title">
                <h2><?php echo __('News Management'); ?></h2>
            </div>
            <div class="sub_section">
                <div class="btn-group">
                    <a href="<?php echo MWB; ?>informasi/index.php"
                       class="btn btn-default"><?php echo __('News List'); ?></a>
                    <a href="<?php echo MWB; ?>informasi/index.php?action=detail"
                       class="btn btn-default"><?php echo __('Add News'); ?></a>
                </div>
                <form name="search" action="<?php echo MWB; ?>informasi/index.php" id="search" method="get"
                      class="form-inline"><?php echo __('Search'); ?>
                    <input type="text" name="keywords" id="keywords" class="form-control col-md-3"/>
                    <select name="field" class="form-control col-md-2">
                        <option value="0"><?php echo __('All Field'); ?></option>
                        <option value="judul"><?php echo __('Title'); ?></option>
                        <option value="isi"><?php echo __('Content'); ?></option>
                    </select>
                    <select name="status" class="form-control col-md-2">
                        <option value=""><?php echo __('All Status'); ?></option>
                        <option value="draft"><?php echo __('Draft'); ?></option>
                        <option value="publish"><?php echo __('Publish'); ?></option>
                    </select>
                    <input type="submit" id="doSearch" value="<?php echo __('Search'); ?>"
                           class="s-btn btn btn-default"/>
                </form>
            </div>
        </div>
    </div>
    <?php
    /* search form end */
}

/* main content */
if (isset($_POST['detail']) OR (isset($_GET['action']) AND $_GET['action'] == 'detail')) {
    if ((isset($_GET['action'])) AND ($_GET['action'] == 'detail')) {
        $log = new AlLibrarian('1153', array("username" => $_SESSION['uname'], "uid" => $_SESSION['uid'], "realname" => $_SESSION['realname']));
    } elseif ((isset($_GET['itemID'])) AND (isset($_GET['detail'])) AND ($_GET['detail'] == true)) {
        $log = new AlLibrarian('1155', array("username" => $_SESSION['uname'], "uid" => $_SESSION['uid'], "realname" => $_SESSION['realname'], "news_id" => $_GET['itemID']));
    }

    if (!($can_read AND $can_write)) {
        die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
    }
    
    /* RECORD FORM */
    // try query
    $itemID = (integer)isset($_POST['itemID']) ? $_POST['itemID'] : (isset($_GET['itemID']) ? $_GET['itemID'] : 0);
    $_sql_rec_q = sprintf('SELECT * FROM informasi WHERE id=%d', $itemID);
    $rec_q = $dbs->query($_sql_rec_q);
    $rec_d = $rec_q->fetch_assoc();

    // create new instance
    $form = new simbio_form_table_AJAX('mainForm', $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'], 'post');
    $form->submit_button_attr = 'name="saveData" value="' . __('Save') . '" class="s-btn btn btn-default"';
    // form table attributes
    $form->table_attr = 'id="dataList" cellpadding="0" cellspacing="0"';
    $form->table_header_attr = 'class="alterCell"';
    $form->table_content_attr = 'class="alterCell2"';

    $visibility = 'makeVisible s-margin__bottom-1';
    // edit mode flag set
    if ($rec_q->num_rows > 0) {
        $form->edit_mode = true;
        // record ID for delete process
        if (!$in_pop_up) {
            // form record id
            $form->record_id = $itemID;
        } else {
            $form->addHidden('updateRecordID', $itemID);
            $form->back_button = false;
        }
        // form record title
        $form->record_title = $rec_d['judul'];
        // submit button attribute
        $form->submit_button_attr = 'name="saveData" value="' . __('Update') . '" class="s-btn btn btn-primary"';
        // element visibility class toogle
        $visibility = 'makeHidden s-margin__bottom-1';
    }

    /* Form Element(s) */
    // News title
    $form->addTextField('text', 'judul', __('Title') . '*', $rec_d['judul'] ?? '', 'class="form-control"',
        __('News title'));

    // News content
    $form->addTextField('textarea', 'isi', __('Content'), $rec_d['isi'] ?? '', 'class="form-control ckeditor" rows="10"',
        __('News content/article'));

    // News status
    $status_options = array(
        array('draft', __('Draft')),
        array('publish', __('Publish'))
    );
    $form->addSelectList('status', __('Status'), $status_options, $rec_d['status'] ?? 'draft', 'class="select2"',
        __('News publication status'));

    // News image
    $str_input = '<div class="row">';
    $str_input .= '<div class="col-2">';
    $str_input .= '<div id="imageFilename" class="s-margin__bottom-1">';
    $upper_dir = '';
    if ($in_pop_up) {
        $upper_dir = '../../';
    }
    if (isset($rec_d['gambar']) && file_exists('../../../images/docs/' . $rec_d['gambar'])) {
        $str_input .= '<a href="' . SWB . 'images/docs/' . ($rec_d['gambar'] ?? '') . '" class="openPopUp notAJAX" title="' . __('Click to enlarge preview') . '">';
        $str_input .= '<img src="' . $upper_dir . '../images/docs/' . urlencode($rec_d['gambar'] ?? '') . '" class="img-fluid rounded" alt="News image">';
        $str_input .= '</a>';
        $str_input .= '<a href="' . MWB . 'informasi/index.php" postdata="removeImage=true&nimg=' . $itemID . '&img=' . ($rec_d['gambar'] ?? '') . '" loadcontainer="imageFilename" class="s-margin__bottom-1 mt-1 s-btn btn btn-danger btn-block makeHidden removeImage">' . __('Remove Image') . '</a>';
    } else {
        $str_input .= '<img src="' . $upper_dir . '../lib/minigalnano/createthumb.php?filename=images/default/image.png&width=130" class="img-fluid rounded" alt="News image">';
    }
    $str_input .= '</div>';
    $str_input .= '</div>';
    $str_input .= '<div class="custom-file col-7">';
    $str_input .= simbio_form_element::textField('file', 'gambar', '', 'class="custom-file-input" id="customFile"');
    $str_input .= '<label class="custom-file-label" for="customFile">' . __('Choose file') . '</label>';
    $str_input .= '<div style="padding: 10px;margin-left: -25px;">';
    $str_input .= '<div>' . __('Or download from url') . '</div>';
    $str_input .= '<div class="form-inline">
                  <input type="text" id="getUrl" class="form-control" style="width:190px" placeholder="Paste url address here">
                  <div class="input-group-append">
                  <button class="btn btn-default" type="button" id="getImage">' . __('Download') . ' <i class="fa fa-spin fa-cog hidden" id="imgLoader"></i></button>
                  </div>
                  </div>';
    $str_input .= '</div>';
    $str_input .= '</div>';
    $str_input .= ' <div class="mt-2 ml-2">Maximum ' . $sysconf['max_image_upload'] . ' KB</div>';
    $str_input .= '</div>';
    $str_input .= '<textarea id="base64picstring" name="base64picstring" style="display: none;"></textarea>';
    $str_input .= '</div></div></div>';
    
    $form->addAnything(__('Image'), $str_input);

    // edit mode message
    if ($form->edit_mode) {
        echo '<div class="s-alert infoBox">'
            . __('You are going to edit news data') . ' : <b>' . $rec_d['judul'] . '</b><br />'
            . __('Created') . '&nbsp;' . date('d F Y H:i:s', strtotime($rec_d['created_at'])) . '<br />'
            . __('Last Updated') . '&nbsp;' . date('d F Y H:i:s', strtotime($rec_d['updated_at']));
        echo '</div>' . "\n";
    }
    // print out the form object
    echo $form->printOut();
    ?>
    <script type="text/javascript">
        $(document).ready(function () {
            $('.removeImage').click(function (e) {
                if (confirm('Are you sure you want to permanently remove this image?')) {
                    return true;
                } else {
                    return false;
                }
            });

            $(document).on('change', '.custom-file-input', function () {
                var input = document.querySelector("#customFile");
                var fReader = new FileReader();
                fReader.readAsDataURL(input.files[0]);
                fReader.onloadend = function (event) {
                    var img = document.querySelector("#imageFilename img");
                    img.src = event.target.result;
                }
                let fileName = $(this).val().replace(/\\/g, '/').replace(/.*\//, '');
                $(this).parent('.custom-file').find('.custom-file-label').text(fileName);
            });

            $('#getImage').click(function () {
                $.post("<?php echo MWB ?>bibliography/scrape_image.php", {imageURL: $('#getUrl').val()})
                    .done(function (data) {
                        if (data.status == 'VALID') {
                            $('#base64picstring').val(data.image);
                            $('#imageFilename img').attr('src', data.message);
                        } else {
                            $('#base64picstring, #getUrl').val('');
                            parent.toastr.error("<?php echo __('Current url is not valid or your internet is down.') ?>", "News Image", {
                                "closeButton": true,
                                "debug": false,
                                "newestOnTop": false,
                                "progressBar": false,
                                "positionClass": "toast-top-right",
                                "preventDuplicates": false,
                                "onclick": null,
                                "showDuration": 300,
                                "hideDuration": 1000,
                                "timeOut": 5000,
                                "extendedTimeOut": 1000,
                                "showEasing": "swing",
                                "hideEasing": "linear",
                                "showMethod": "fadeIn",
                                "hideMethod": "fadeOut"
                            })
                        }
                    });
            });
        });
    </script>
    <?php
} else {
    # ADV LOG SYSTEM - STIIL EXPERIMENTAL
    $log = new AlLibrarian('1151', array("username" => $_SESSION['uname'], "uid" => $_SESSION['uid'], "realname" => $_SESSION['realname']));

    require SIMBIO . 'simbio_UTILS/simbio_tokenizecql.inc.php';
    
    // number of records to show in list
    $news_result_num = ($sysconf['biblio_result_num'] > 100) ? 100 : $sysconf['biblio_result_num'];

    // create datagrid
    $datagrid = new simbio_datagrid();
    
    // table spec
    $table_spec = 'informasi';
    $str_criteria = '1=1';
    
    if ($can_read AND $can_write) {
        $datagrid->setSQLColumn('id', 'judul AS \'' . __('Title') . '\'',
            'LEFT(isi, 200) AS \'' . __('Content Preview') . '\'',
            'status AS \'' . __('Status') . '\'',
            'gambar AS \'' . __('Image') . '\'',
            'created_at AS \'' . __('Created') . '\'',
            'updated_at AS \'' . __('Last Update') . '\'');
    } else {
        $datagrid->setSQLColumn('judul AS \'' . __('Title') . '\'',
            'LEFT(isi, 200) AS \'' . __('Content Preview') . '\'',
            'status AS \'' . __('Status') . '\'',
            'created_at AS \'' . __('Created') . '\'',
            'updated_at AS \'' . __('Last Update') . '\'');
    }
    $datagrid->invisible_fields = array(0);
    $datagrid->setSQLorder('created_at DESC');
    
    // is there any search
    if (isset($_GET['keywords']) AND $_GET['keywords']) {
        $keywords = $dbs->escape_string(trim($_GET['keywords']));
        if ($_GET['field'] != '0' AND in_array($_GET['field'], array('judul', 'isi'))) {
            $field = $_GET['field'];
            $str_criteria .= " AND $field LIKE '%$keywords%'";
        } else {
            $str_criteria .= " AND (judul LIKE '%$keywords%' OR isi LIKE '%$keywords%')";
        }
    }
    
    if (isset($_GET['status']) && $_GET['status'] != '') {
        $status = $dbs->escape_string($_GET['status']);
        $str_criteria .= " AND status = '$status'";
    }

    $datagrid->setSQLcriteria($str_criteria);
    
    // set table and table header attributes
    $datagrid->table_attr = 'id="dataList" class="s-table table"';
    $datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';
    // set delete proccess URL
    $datagrid->chbox_form_URL = $_SERVER['PHP_SELF'];
    $datagrid->debug = true;
    
    // modify image column to show thumbnail
    if ($can_read AND $can_write) {
        $datagrid->modifyColumnContent(4, 'callback{showNewsImage}');
    } else {
        $datagrid->modifyColumnContent(3, 'callback{showNewsImage}');
    }
    
    // modify status column to show badge
    if ($can_read AND $can_write) {
        $datagrid->modifyColumnContent(3, 'callback{showStatusBadge}');
    } else {
        $datagrid->modifyColumnContent(2, 'callback{showStatusBadge}');
    }
    
    // modify content preview
    if ($can_read AND $can_write) {
        $datagrid->modifyColumnContent(2, 'callback{stripTagsAndTrim}');
    } else {
        $datagrid->modifyColumnContent(1, 'callback{stripTagsAndTrim}');
    }

    // put the result into variables
    $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, $news_result_num, ($can_read AND $can_write));
    
    if (isset($_GET['keywords']) AND $_GET['keywords']) {
        $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords'));
        echo '<div class="infoBox">' . $msg . ' : "' . htmlentities($_GET['keywords']) . '"<div>' . __('Query took') . ' <b>' . $datagrid->query_time . '</b> ' . __('second(s) to complete') . '</div></div>';
    }
    
    echo $datagrid_result;
}

// Helper functions for datagrid
function showNewsImage($value, $row) {
    if (!empty($value) && file_exists(IMGBS . 'docs/' . $value)) {
        return '<img src="' . SWB . 'lib/minigalnano/createthumb.php?filename=images/docs/' . urlencode($value) . '&width=50" class="img-thumbnail" alt="News image">';
    }
    return '<img src="' . SWB . 'lib/minigalnano/createthumb.php?filename=images/default/image.png&width=50" class="img-thumbnail" alt="No image">';
}

function showStatusBadge($value, $row) {
    if ($value == 'publish') {
        return '<span class="badge badge-success">' . __('Published') . '</span>';
    }
    return '<span class="badge badge-warning">' . __('Draft') . '</span>';
}

function stripTagsAndTrim($value, $row) {
    $value = strip_tags($value);
    if (strlen($value) > 200) {
        $value = substr($value, 0, 200) . '...';
    }
    return $value;
}
/* main content end */
?>