<?php

/**
 * @Created by          : Waris Agung Widodo (ido.alit@gmail.com)
 * @Date                : 10/09/20 21.31
 * @File name           : MemberController.php
 */

require_once 'Controller.php';
require_once __DIR__ . '/../helpers/Image.php';

class MemberController extends Controller
{

    use Image;

    protected $sysconf;

    /**
     * @var mysqli
     */
    protected $db;

    function __construct($sysconf, $obj_db)
    {
        $this->sysconf = $sysconf;
        $this->db = $obj_db;
    }

    function getTopMember()
    {
        $limit = 3;
        $year = date('Y');
        $sql = "SELECT m.member_name, mm.member_type_name, m.member_image, COUNT(*) AS total, GROUP_CONCAT(i.biblio_id SEPARATOR ';') AS biblio_id
          FROM loan AS l
          LEFT JOIN member AS m ON l.member_id=m.member_id
          LEFT JOIN mst_member_type AS mm ON m.member_type_id=mm.member_type_id
          LEFT JOIN item As i ON l.item_code=i.item_code
          WHERE
            l.loan_date LIKE '{$year}-%' AND
            m.member_name IS NOT NULL
          GROUP BY m.member_id
          ORDER BY total DESC
          LIMIT {$limit}";

        $query = $this->db->query($sql);
        $return = array();
        if ($query) {
            while ($data = $query->fetch_assoc()) {
                $title = array_unique(explode(';', $data['biblio_id']));
                $return[] = array(
                    'name' => $data['member_name'],
                    'type' => $data['member_type_name'],
                    'image' =>  $this->getImagePath($data['member_image'], 'persons'),
                    'total' => $data['total'],
                    'total_title' => count($title),
                    'order' => $data['total'] + count($title)
                );
            }
        }

        usort($return, function ($a, $b) {
            return $b['order'] <=> $a['order'];
        });

        parent::withJson($return);
    }

    public function login()
    {
        header('Content-Type: application/json');

        $body = json_decode(file_get_contents('php://input'), true);

        $memberId = trim($body['member_id'] ?? '');
        $password = trim($body['password'] ?? '');

        if (!$memberId || !$password) {
            http_response_code(422);

            echo json_encode([
                'success' => false,
                'message' => 'Member ID dan password wajib diisi'
            ]);

            return;
        }

        $memberId = mysqli_real_escape_string($this->db, $memberId);

        $sql = "
            SELECT *
            FROM member
            WHERE member_id = '$memberId'
            LIMIT 1
        ";

        $query = $this->db->query($sql);

        if (!$query || $query->num_rows === 0) {
            http_response_code(401);

            echo json_encode([
                'success' => false,
                'message' => 'NIM atau password salah'
            ]);

            return;
        }

        $member = $query->fetch_assoc();

        if (!password_verify($password, $member['mpasswd'])) {
            http_response_code(401);

            echo json_encode([
                'success' => false,
                'message' => 'NIM atau password salah'
            ]);

            return;
        }

        $token = bin2hex(random_bytes(32));

        $this->db->query("
            INSERT INTO member_tokens
            (
                member_id,
                token,
                expired_at
            )
            VALUES
            (
                '{$member['member_id']}',
                '$token',
                DATE_ADD(NOW(), INTERVAL 30 DAY)
            )
        ");

        echo json_encode([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => [
                    'member_id' => $member['member_id'],
                    'member_name' => $member['member_name'],
                    'member_email' => $member['member_email'],
                    'member_image' => $member['member_image'],
                    'fakultas' => $member['fakultas'],
                    'jurusan' => $member['jurusan'],
                    'birth_date' => $member['birth_date'],
                    'address' => $member['member_address'],
                    'phone' => $member['member_phone'],
                    'expire_date' => $member['expire_date'],
                    'barcode_id' => $member['barcode_id'],
                ]
            ]
        ]);
    }

    public function me()
    {
        header('Content-Type: application/json');

        $member = $this->getAuthMember();

        if (!$member) {
            http_response_code(401);

            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized'
            ]);

            return;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'member_id' => $member['member_id'],
                'member_name' => $member['member_name'],
                'member_email' => $member['member_email'],
                'member_image' => $member['member_image']
            ]
        ]);
    }

    public function logout()
    {
        header('Content-Type: application/json');

        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            echo json_encode([
                'success' => true
            ]);

            return;
        }

        $token = str_replace(
            'Bearer ',
            '',
            $headers['Authorization']
        );

        $token = mysqli_real_escape_string(
            $this->db,
            $token
        );

        $this->db->query("
            DELETE FROM member_tokens
            WHERE token='$token'
        ");

        echo json_encode([
            'success' => true
        ]);
    }

    private function getAuthMember()
    {
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            return false;
        }

        $token = str_replace(
            'Bearer ',
            '',
            $headers['Authorization']
        );

        $token = mysqli_real_escape_string(
            $this->db,
            $token
        );

        $sql = "
            SELECT m.*
            FROM member_tokens mt
            JOIN member m
                ON m.member_id = mt.member_id
            WHERE mt.token = '$token'
            AND (
                mt.expired_at IS NULL
                OR mt.expired_at > NOW()
            )
            LIMIT 1
        ";

        $query = $this->db->query($sql);

        if (!$query || $query->num_rows === 0) {
            return false;
        }

        return $query->fetch_assoc();
    }

    // public function getRecommendation()
    // {
    //     header('Content-Type: application/json');

    //     $member = $this->getAuthMember();

    //     if (!$member) {
    //         http_response_code(401);

    //         echo json_encode([
    //             'success' => false,
    //             'message' => 'Unauthorized'
    //         ]);

    //         return;
    //     }

    //     $jurusan = trim($member['jurusan']);

    //     if (!$jurusan) {
    //         echo json_encode([
    //             'success' => true,
    //             'data' => []
    //         ]);

    //         return;
    //     }

    //     $jurusanEscaped = mysqli_real_escape_string(
    //         $this->db,
    //         $jurusan
    //     );

    //     $sql = "
    //     SELECT
    //         biblio_id,
    //         title,
    //         image,
    //         publish_year,
    //         classification
    //     FROM biblio
    //     WHERE
    //         opac_hide = 0
    //         AND (
    //             title LIKE '%{$jurusanEscaped}%'
    //             OR notes LIKE '%{$jurusanEscaped}%'
    //             OR labels LIKE '%{$jurusanEscaped}%'
    //             OR classification LIKE '%{$jurusanEscaped}%'
    //         )
    //     ORDER BY input_date DESC
    //     LIMIT 20
    // ";

    //     $query = $this->db->query($sql);

    //     $rows = [];

    //     while ($row = $query->fetch_assoc()) {
    //         $rows[] = $row;
    //     }

    //     echo json_encode([
    //         'success' => true,
    //         'jurusan' => $jurusan,
    //         'data' => $rows
    //     ]);
    // }

    public function getRecommendation()
{
    die('MASUK');
}
}
