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

    public function addToCart()
    {
        header('Content-Type: application/json');

        // 1. Verifikasi Auth Member
        $member = $this->getAuthMember();
        if (!$member) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        // 2. Ambil Input data biblio_id
        $input = json_decode(file_get_contents('php://input'), true);
        $biblioId = isset($input['biblio_id']) ? (int)$input['biblio_id'] : 0;

        if ($biblioId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID Buku tidak valid']);
            return;
        }

        $memberId = mysqli_real_escape_string($this->db, $member['member_id']);

        // 3. VALIDASI SYARAT A: Status Keanggotaan (Expired / Pending)
        // Mengecek apakah masa aktif member sudah habis
        $todayStr = date('Y-m-d');
        if (!empty($member['expire_date']) && $member['expire_date'] < $todayStr) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Gagal. Masa keanggotaan (Membership) Anda telah kedaluwarsa!'
            ]);
            return;
        }
        
        // Jika ada status pending/lock sirkulasi di data member
        if (isset($member['is_pending']) && $member['is_pending'] == 1) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Gagal. Akun keanggotaan Anda sedang ditangguhkan (Pending State)!'
            ]);
            return;
        }

        // 4. VALIDASI SYARAT B: Cek Pinjaman Terlambat (Overdue)
        $sqlCheck = "SELECT due_date FROM loan WHERE member_id = '{$memberId}' AND is_return = 0";
        $queryCheck = $this->db->query($sqlCheck);
        $today = new \DateTime();

        while ($row = $queryCheck->fetch_assoc()) {
            $dueDate = new \DateTime($row['due_date']);
            $sisaHari = (int)$today->diff($dueDate)->format('%r%a');

            if ($sisaHari < 0) {
                http_response_code(403); 
                echo json_encode([
                    'success' => false,
                    'message' => 'Gagal memasukkan keranjang. Anda memiliki pinjaman buku yang terlambat dikembalikan!'
                ]);
                return;
            }
        }

        // 5. VALIDASI SYARAT C: Cek Limit Maksimal Booking Keanggotaan (Sesuai Aturan SLiMS)
        // Mengambil limit kuota booking berdasarkan tipe anggota (mst_member_type)
        $memberTypeId = (int)$member['member_type_id'];
        $reserveLimitQ = $this->db->query("SELECT reserve_limit FROM mst_member_type WHERE member_type_id = {$memberTypeId}");
        $reserveLimit = 3; // default fallback jika tidak ketemu
        if ($reserveLimitQ && $rowLimit = $reserveLimitQ->fetch_row()) {
            $reserveLimit = (int)$rowLimit[0];
        }

        // Hitung total booking aktif user saat ini di database
        $currentReserveQ = $this->db->query("SELECT COUNT(*) FROM booking WHERE member_id = '{$memberId}'");
        $currentReserve = 0;
        if ($currentReserveQ && $rowCurrent = $currentReserveQ->fetch_row()) {
            $currentReserve = (int)$rowCurrent[0];
        }

        if ($currentReserve >= $reserveLimit) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "Gagal. Batas maksimal keranjang/booking Anda ({$reserveLimit} buku) telah tercapai."
            ]);
            return;
        }

        // 6. VALIDASI SYARAT D: Proteksi Duplikasi Item di Dalam Keranjang
        $sqlDuplicateCheck = "SELECT booking_id FROM booking WHERE member_id = '{$memberId}' AND biblio_id = {$biblioId} LIMIT 1";
        $queryDuplicate = $this->db->query($sqlDuplicateCheck);
        if ($queryDuplicate && $queryDuplicate->num_rows > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Buku ini sudah ada di dalam keranjang belanja Anda.'
            ]);
            return;
        }

        // 7. EKSEKUSI PENYIMPANAN KE TABEL BOOKING BAWAAN SLiMS
        $bookingDate = $today->format('Y-m-d H:i:s');
        // Expired booking otomatis diset 3 hari kedepan semenjak klik dilakukan
        $expiredDate = $today->modify('+3 days')->format('Y-m-d H:i:s'); 

        $sqlInsert = "INSERT INTO booking (member_id, biblio_id, booking_date, expired_date) 
                      VALUES ('{$memberId}', {$biblioId}, '{$bookingDate}', '{$expiredDate}')";

        if ($this->db->query($sqlInsert)) {
            echo json_encode([
                'success' => true,
                'message' => 'Buku berhasil dimasukkan ke keranjang!'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'message' => 'Gagal menyimpan data sirkulasi: ' . $this->db->error
            ]);
        }
    }
}
