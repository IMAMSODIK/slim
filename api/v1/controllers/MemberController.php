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

    public function getLoans()
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

        $type = $_GET['type'] ?? 'active';

        $memberId = mysqli_real_escape_string(
            $this->db,
            $member['member_id']
        );

        /*
     * BASE QUERY
     */
        $where = "l.member_id = '{$memberId}'";

        if ($type === 'active') {
            $where .= " AND l.is_return = 0";
        }

        $sql = "
        SELECT
            l.loan_id,
            l.loan_date,
            l.due_date,
            l.is_return,
            b.title,
            b.image
        FROM loan l
        INNER JOIN item i ON i.item_code = l.item_code
        INNER JOIN biblio b ON b.biblio_id = i.biblio_id
        WHERE {$where}
        ORDER BY l.loan_date DESC
    ";

        $query = $this->db->query($sql);

        if (!$query) {
            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => $this->db->error
            ]);
            return;
        }

        $today = new DateTime();
        $data = [];

        while ($row = $query->fetch_assoc()) {

            $loanDate = new DateTime($row['loan_date']);
            $dueDate  = new DateTime($row['due_date']);

            /*
         * Lama pinjam
         */
            $lamaPinjam = $loanDate->diff($today)->days;

            /*
         * Sisa hari (negatif = terlambat)
         */
            $sisaHari = (int)$today->diff($dueDate)->format('%r%a');

            /*
         * STATUS LOGIC
         */
            if ((int)$row['is_return'] === 1) {
                $status = 'Selesai';
            } else {
                $status = $sisaHari < 0 ? 'Terlambat' : 'Aktif';
            }

            $data[] = [
                'loan_id' => (int)$row['loan_id'],
                'title' => $row['title'],
                'cover' => $this->getImagePath($row['image'], 'docs'),
                'borrow_date' => $row['loan_date'],
                'due_date' => $row['due_date'],
                'lama_pinjam' => $lamaPinjam,
                'sisa_hari' => $sisaHari,
                'status' => $status
            ];
        }

        echo json_encode([
            'success' => true,
            'type' => $type,
            'total' => count($data),
            'data' => $data
        ]);
    }

    public function getRecommendation()
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

        $jurusan = trim($member['jurusan']);

        if (!$jurusan) {
            echo json_encode([
                'success' => true,
                'data' => []
            ]);

            return;
        }

        $jurusanEscaped = mysqli_real_escape_string(
            $this->db,
            $jurusan
        );

        $sql = "
        SELECT
            biblio_id,
            title,
            image,
            publish_year,
            classification
        FROM biblio
        WHERE
            opac_hide = 0
            AND (
                title LIKE '%{$jurusanEscaped}%'
                OR notes LIKE '%{$jurusanEscaped}%'
                OR labels LIKE '%{$jurusanEscaped}%'
                OR classification LIKE '%{$jurusanEscaped}%'
            )
        ORDER BY input_date DESC
        LIMIT 20
    ";

        $query = $this->db->query($sql);

        $rows = [];

        while ($row = $query->fetch_assoc()) {
            $rows[] = $row;
        }

        echo json_encode([
            'success' => true,
            'jurusan' => $jurusan,
            'data' => $rows
        ]);
    }

    public function getReserveStatus($id)
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

        $memberId = mysqli_real_escape_string(
            $this->db,
            $member['member_id']
        );

        $biblioId = (int)$id;

        /*
     * MEMBER TYPE
     */
        $memberType = $this->db->query("
        SELECT enable_reserve,reserve_limit
        FROM mst_member_type
        WHERE member_type_id=" . $member['member_type_id'] . "
    ");

        $memberTypeData = $memberType->fetch_assoc();

        /*
     * TOTAL RESERVE MEMBER
     */
        $reserveCount = $this->db->query("
        SELECT COUNT(*) total
        FROM reserve
        WHERE member_id='$memberId'
    ");

        $reserveCountData = $reserveCount->fetch_assoc();

        /*
     * SUDAH RESERVE BUKU INI?
     */
        $alreadyReserve = $this->db->query("
        SELECT reserve_id
        FROM reserve
        WHERE member_id='$memberId'
        AND biblio_id='$biblioId'
        LIMIT 1
    ");

        /*
     * CEK ITEM YANG DIPINJAM
     */
        $loanedItems = $this->db->query("
        SELECT
            i.item_code
        FROM item i
        INNER JOIN loan l
            ON l.item_code=i.item_code
        WHERE i.biblio_id='$biblioId'
        AND l.is_lent=1
        AND l.is_return=0
        AND l.member_id!='$memberId'
    ");

        $loanedCount = $loanedItems->num_rows;

        /*
     * ITEM TERSEDIA
     */
        $availableItems = $this->db->query("
        SELECT i.item_code
        FROM item i
        WHERE i.biblio_id='$biblioId'
        AND i.item_code NOT IN (
            SELECT item_code
            FROM loan
            WHERE is_lent=1
            AND is_return=0
        )
    ");

        $availableCount = $availableItems->num_rows;

        $canReserve = true;
        $message = 'Buku dapat direservasi';

        if (!$memberTypeData['enable_reserve']) {

            $canReserve = false;
            $message = 'Reservasi tidak diizinkan';
        } elseif (
            $reserveCountData['total']
            >=
            $memberTypeData['reserve_limit']
        ) {

            $canReserve = false;
            $message = 'Batas reservasi tercapai';
        } elseif ($alreadyReserve->num_rows > 0) {

            $canReserve = false;
            $message = 'Anda sudah melakukan reservasi';
        } elseif ($availableCount > 0) {

            $canReserve = false;
            $message = 'Buku tersedia, silakan pinjam langsung';
        } elseif ($loanedCount < 1) {

            $canReserve = false;
            $message = 'Tidak ada item yang bisa direservasi';
        }

        echo json_encode([
            'success' => true,
            'can_reserve' => $canReserve,
            'message' => $message,
            'available_items' => $availableCount,
            'loaned_items' => $loanedCount,
            'reserve_limit' => (int)$memberTypeData['reserve_limit'],
            'current_reserve' => (int)$reserveCountData['total']
        ]);
    }

    public function reserveBook()
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

        $body = json_decode(
            file_get_contents('php://input'),
            true
        );

        $biblioId = (int)($body['biblio_id'] ?? 0);

        if (!$biblioId) {

            echo json_encode([
                'success' => false,
                'message' => 'biblio_id required'
            ]);

            return;
        }

        $memberId = mysqli_real_escape_string(
            $this->db,
            $member['member_id']
        );

        /*
     * CARI ITEM YANG SEDANG DIPINJAM
     */
        $itemQuery = $this->db->query("
        SELECT
            i.item_code
        FROM item i
        INNER JOIN loan l
            ON l.item_code=i.item_code
        WHERE i.biblio_id='$biblioId'
        AND l.is_lent=1
        AND l.is_return=0
        AND l.member_id!='$memberId'
        LIMIT 1
    ");

        if ($itemQuery->num_rows < 1) {

            echo json_encode([
                'success' => false,
                'message' => 'Tidak ada item yang dapat direservasi'
            ]);

            return;
        }

        $item = $itemQuery->fetch_assoc();

        /*
     * CEK DUPLIKAT
     */
        $exists = $this->db->query("
        SELECT reserve_id
        FROM reserve
        WHERE member_id='$memberId'
        AND biblio_id='$biblioId'
        LIMIT 1
    ");

        if ($exists->num_rows > 0) {

            echo json_encode([
                'success' => false,
                'message' => 'Sudah pernah reserve'
            ]);

            return;
        }

        $reserveDate = date('Y-m-d H:i:s');

        $insert = $this->db->query("
        INSERT INTO reserve(
            member_id,
            biblio_id,
            item_code,
            reserve_date
        )
        VALUES(
            '$memberId',
            '$biblioId',
            '" . $item['item_code'] . "',
            '$reserveDate'
        )
    ");

        if (!$insert) {

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => $this->db->error
            ]);

            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Reservasi berhasil',
            'item_code' => $item['item_code']
        ]);
    }

    public function getReserves()
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

        $memberId = mysqli_real_escape_string(
            $this->db,
            $member['member_id']
        );

        $query = $this->db->query("
        SELECT
            r.reserve_id,
            r.reserve_date,
            r.item_code,
            b.title,
            b.image
        FROM reserve r
        INNER JOIN biblio b
            ON b.biblio_id=r.biblio_id
        WHERE r.member_id='$memberId'
        ORDER BY r.reserve_date DESC
    ");

        $data = [];

        while ($row = $query->fetch_assoc()) {

            $data[] = [
                'reserve_id' => (int)$row['reserve_id'],
                'title' => $row['title'],
                'cover' => $this->getImagePath(
                    $row['image'],
                    'docs'
                ),
                'item_code' => $row['item_code'],
                'reserve_date' => $row['reserve_date']
            ];
        }

        echo json_encode([
            'success' => true,
            'total' => count($data),
            'data' => $data
        ]);
    }
}
