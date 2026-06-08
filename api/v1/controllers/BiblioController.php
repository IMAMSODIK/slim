<?php

/**
 * @author              : Waris Agung Widodo
 * @Date                : 2017-07-05 12:15:12
 * @Last Modified by    : ido
 * @Last Modified time  : 2017-07-05 15:08:08
 *
 * Copyright (C) 2017  Waris Agung Widodo (ido.alit@gmail.com)
 */

require_once 'Controller.php';
require_once __DIR__ . '/../helpers/Image.php';
require_once __DIR__ . '/../helpers/Cache.php';

class BiblioController extends Controller
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

    public function getAll()
    {
        global $dbs;

        header('Content-Type: application/json');

        // ======================
        // INPUT
        // ======================
        $page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $page  = max($page, 1);
        $limit = max($limit, 1);
        $offset = ($page - 1) * $limit;

        // ======================
        // SEARCH SAFE BUILD
        // ======================
        $where = "1=1";

        if (!empty($search)) {
            $search = mysqli_real_escape_string($dbs, $search);

            $where .= " AND (
            b.title LIKE '%$search%'
            OR b.isbn_issn LIKE '%$search%'
            OR b.publish_year LIKE '%$search%'
        )";
        }

        // ======================
        // TOTAL (FIXED QUERY)
        // ======================
        $totalQuery = $dbs->query("
        SELECT COUNT(*) AS total
        FROM biblio b
        WHERE $where
    ");

        $total = (int)$totalQuery->fetch_assoc()['total'];
        $lastPage = ($limit > 0) ? (int)ceil($total / $limit) : 1;

        // ======================
        // HARD STOP
        // ======================
        if ($page > $lastPage && $total > 0) {
            echo json_encode([
                'success' => true,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => false,
                'data' => []
            ]);
            exit;
        }

        // ======================
        // DATA QUERY (FIXED JOIN POSITION)
        // ======================
        $query = $dbs->query("
        SELECT
            b.biblio_id,
            b.title,
            b.isbn_issn,
            b.publish_year,
            b.image,
            b.call_number,
            p.publisher_name AS publisher
        FROM biblio b
        LEFT JOIN mst_publisher p
            ON b.publisher_id = p.publisher_id
        WHERE $where
        ORDER BY b.biblio_id DESC
        LIMIT $limit OFFSET $offset
    ");

        $rows = [];

        while ($row = $query->fetch_assoc()) {
            $row['cover_url'] = !empty($row['image'])
                ? SWB . 'images/docs/' . $row['image']
                : null;

            $rows[] = $row;
        }

        // ======================
        // RESPONSE
        // ======================
        echo json_encode([
            'success' => true,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'data' => $rows
        ]);
    }

    public function getPopular()
    {
        $cache_name = 'biblio_popular';
        if (!is_null($json = Cache::get($cache_name))) return parent::withJson($json);

        $limit = $this->sysconf['template']['classic_popular_collection_item'];
        $sql = "SELECT b.biblio_id, b.title, b.image, COUNT(*) AS total
          FROM loan AS l
          LEFT JOIN item AS i ON l.item_code=i.item_code
          LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id
          WHERE b.title IS NOT NULL
          GROUP BY b.biblio_id
          ORDER BY total DESC
          LIMIT {$limit}";

        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }
        if ($query->num_rows < $limit) {
            $need = $limit - $query->num_rows;
            if ($need < 0) {
                $need = $limit;
            }

            $sql = "SELECT biblio_id, title, image FROM biblio ORDER BY last_update DESC LIMIT {$need}";
            $query = $this->db->query($sql);
            while ($data = $query->fetch_assoc()) {
                $data['image'] = $this->getImagePath($data['image']);
                $return[] = $data;
            }
        }

        Cache::set($cache_name, json_encode($return));
        parent::withJson($return);
    }

    public function getLatest()
    {
        $limit = 6;

        $sql = "SELECT biblio_id, title, image, publisher_id
          FROM biblio
          ORDER BY last_update DESC
          LIMIT {$limit}";

        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }

        parent::withJson($return);
    }

    public function getLatestMobile()
    {
        global $dbs;

        $limit = 6;

        $query = $dbs->query("
        SELECT
            b.biblio_id,
            b.title,
            b.isbn_issn,
            b.publish_year,
            b.image,
            b.call_number,
            p.publisher_name AS publisher
        FROM biblio b
        LEFT JOIN mst_publisher p
            ON b.publisher_id = p.publisher_id
        ORDER BY b.last_update DESC
        LIMIT {$limit}
    ");

        $rows = [];

        while ($row = $query->fetch_assoc()) {

            $row['cover_url'] = !empty($row['image'])
                ? SWB . 'images/docs/' . $row['image']
                : null;

            $rows[] = $row;
        }

        header('Content-Type: application/json');

        echo json_encode([
            'success' => true,
            'page' => 1,
            'limit' => $limit,
            'total' => count($rows),
            'last_page' => 1,
            'has_more' => false,
            'data' => $rows
        ]);
    }

    public function getTotalAll()
    {
        $query = $this->db->query("SELECT COUNT(biblio_id) FROM biblio");
        parent::withJson([
            'data' => ($query->fetch_row())[0]
        ]);
    }

    public function getByGmd($gmd)
    {
        $limit = 3;
        $sql = "SELECT b.biblio_id, b.title, b.image, b.notes
          FROM biblio AS b, mst_gmd AS g
          WHERE b.gmd_id=g.gmd_id AND g.gmd_name='$gmd'
          ORDER BY b.last_update DESC
          LIMIT {$limit}";
        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }

        parent::withJson($return);
    }

    public function getByCollType($coll_type)
    {
        $limit = 3;
        $sql = "SELECT b.biblio_id, b.title, b.image, b.notes
          FROM biblio AS b, item AS i, mst_coll_type AS c
          WHERE b.biblio_id=i.biblio_id AND i.coll_type_id=c.coll_type_id AND c.coll_type_name='$coll_type'
          ORDER BY b.last_update DESC
          LIMIT {$limit}";
        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }

        parent::withJson($return);
    }

    public function getDetailModel($id)
    {
        $sql = "
        SELECT
            b.biblio_id,
            b.title,
            b.sor,
            b.edition,
            b.isbn_issn,
            b.publish_year,
            b.collation,
            b.series_title,
            b.call_number,
            b.classification,
            b.notes,
            b.image,
            b.file_att,
            b.labels,
            b.spec_detail_info,
            b.input_date,
            b.last_update,

            g.gmd_name,
            g.icon_image,

            p.publisher_name,

            l.language_name,

            pl.place_name,

            f.frequency,

            ct.content_type,

            mt.media_type,

            crt.carrier_type

        FROM biblio b

        LEFT JOIN mst_gmd g
            ON b.gmd_id = g.gmd_id

        LEFT JOIN mst_publisher p
            ON b.publisher_id = p.publisher_id

        LEFT JOIN mst_language l
            ON b.language_id = l.language_id

        LEFT JOIN mst_place pl
            ON b.publish_place_id = pl.place_id

        LEFT JOIN mst_frequency f
            ON b.frequency_id = f.frequency_id

        LEFT JOIN mst_content_type ct
            ON b.content_type_id = ct.id

        LEFT JOIN mst_media_type mt
            ON b.media_type_id = mt.id

        LEFT JOIN mst_carrier_type crt
            ON b.carrier_type_id = crt.id

        WHERE b.biblio_id = ?

        LIMIT 1
    ";

        $stmt = $this->db->prepare($sql);

        $stmt->bind_param('i', $id);

        $stmt->execute();

        return $stmt
            ->get_result()
            ->fetch_assoc();
    }

    public function getDetail($id)
    {
        $data = $this->getDetailModel($id);

        if (!$data) {
            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'Data tidak ditemukan'
            ]);

            return;
        }

        echo json_encode([
            'success' => true,
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
}
