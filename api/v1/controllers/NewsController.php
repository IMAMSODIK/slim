<?php

require_once 'Controller.php';

class NewsController extends Controller
{
    protected $db;
    protected $sysconf;

    public function __construct($sysconf, $db)
    {
        $this->sysconf = $sysconf;
        $this->db = $db;
    }

    /**
     * GET /news
     */
    public function index()
    {
        header('Content-Type: application/json');

        $limit = isset($_GET['limit'])
            ? (int) $_GET['limit']
            : 10;

        $sql = "
            SELECT
                id,
                judul,
                isi,
                gambar,
                created_at
            FROM informasi
            WHERE status='publish'
            ORDER BY created_at DESC
            LIMIT {$limit}
        ";

        $query = $this->db->query($sql);

        $data = [];

        while ($row = $query->fetch_assoc()) {

            $image = null;

            if (!empty($row['gambar'])) {
                $image = SWB . 'images/docs/' . $row['gambar'];
            }

            $data[] = [
                'id' => (int)$row['id'],
                'title' => $row['judul'],
                'content' => strip_tags($row['isi']),
                'image' => $image,
                'created_at' => $row['created_at']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * GET /news/{id}
     */
    public function detail($id)
    {
        header('Content-Type: application/json');

        $id = (int)$id;

        $sql = "
            SELECT *
            FROM informasi
            WHERE id={$id}
            AND status='publish'
            LIMIT 1
        ";

        $query = $this->db->query($sql);

        if (!$query || $query->num_rows == 0) {

            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'Berita tidak ditemukan'
            ]);

            return;
        }

        $row = $query->fetch_assoc();

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int)$row['id'],
                'title' => $row['judul'],
                'content' => $row['isi'],
                'image' => !empty($row['gambar'])
                    ? SWB . 'images/docs/' . $row['gambar']
                    : null,
                'created_at' => $row['created_at']
            ]
        ]);
    }
}