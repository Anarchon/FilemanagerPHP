<?php
error_reporting(E_ALL);
session_start();

class DirectoryListing {
    // =================================================================
    // KONFIGURATION
    // =================================================================
    public $startDirectory = '.';
    public $pageTitle = "Anarchon's File Manager Pro";
    
    // Auth & Sicherheit
    public $passwordProtect = true;
    public $passwordHash = 'y$qDrRSKcQcuyifjG10Yjqfu4HYfDLialD7O6.CCSleqlbBr6NQXu22'; // LASS DIES LEER! Der Assistent füllt es für den Admin aus.
    
    // GAST MODUS
    public $enableGuestMode = true; // Gast-Zugang erlauben? (Nur Lesen/Download)
    public $guestPasswordHash = ''; // Optional: Hash für Gäste. Wenn leer, gibt es einen offenen Gast-Button.

    // Funktionen aktivieren/deaktivieren (Gilt für Admins)
    public $enableUploads = true;
    public $enableDirectoryCreation = true;
    public $enableFileDeletion = true;
    public $enableDirectoryDeletion = true;
    public $enableRename = true;
    public $enableTextEditor = true;
    public $enableBulkActions = true;
    
    // Datei-Upload & Anzeige Filter
    public $allowedUploadMimeTypes = [
        'image/jpeg', 'image/gif', 'image/png', 'image/bmp', 'image/webp',
        'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/ogg', 'audio/wav',
        'video/mp4', 'video/webm', 'video/ogg',
        'text/plain', 'text/html', 'text/css', 'text/csv', 'application/json',
        'application/pdf', 'application/zip', 'application/x-rar-compressed',
        'application/x-msdownload', 'application/octet-stream' // Für ISOs/Exe
    ];
    public $blockedUploadExtensions = ['php', 'phtml', 'sh', 'phar']; 
    
    public $ignoredFileExtensions = ['ini'];
    public $ignoredFileNames = ['.htaccess', '.DS_Store', 'Thumbs.db', 'index.php'];
    public $ignoredDirectories = ['Windows', '.git'];

    // =================================================================
    // INTERNE VARIABLEN
    // =================================================================
    private $currentDirectory = '';
    private $basePath = null;
    private $csrfToken = '';
    private $msg = []; 

    public function __construct() {
        $this->basePath = realpath($this->startDirectory);
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->csrfToken = $_SESSION['csrf_token'];
        
        // Kompatibilität mit alter Session
        if (isset($_SESSION['evdir_loggedin']) && empty($_SESSION['evdir_role'])) {
            $_SESSION['evdir_role'] = 'admin';
        }
    }

    private function isAdmin() {
        return !$this->passwordProtect || (isset($_SESSION['evdir_role']) && $_SESSION['evdir_role'] === 'admin');
    }

    public function run() {
        // --- Setup Assistent (Nur für Admin-Passwort) ---
        if ($this->passwordProtect && empty($this->passwordHash)) {
            return $this->handleSetupWizard();
        }

        // --- Auth Check ---
        if (isset($_GET['logout'])) {
            unset($_SESSION['evdir_loggedin'], $_SESSION['evdir_role']);
            header("Location: ?");
            exit;
        }

        if (isset($_POST['login_password'])) {
            if (password_verify($_POST['login_password'], $this->passwordHash)) {
                $_SESSION['evdir_role'] = 'admin';
            } elseif ($this->enableGuestMode && !empty($this->guestPasswordHash) && password_verify($_POST['login_password'], $this->guestPasswordHash)) {
                $_SESSION['evdir_role'] = 'guest';
            } else {
                $this->msg = ['type' => 'danger', 'text' => 'Falsches Passwort!'];
            }
        }
        
        // Offener Gast-Login (Button)
        if (isset($_POST['login_guest']) && $this->enableGuestMode && empty($this->guestPasswordHash)) {
            $_SESSION['evdir_role'] = 'guest';
        }

        if ($this->passwordProtect && empty($_SESSION['evdir_role'])) {
            return $this->renderLogin();
        }

        // --- Verzeichnis-Navigation & Security ---
        $this->currentDirectory = isset($_GET['dir']) ? trim($_GET['dir'], '/\\') : '';
        $targetPath = $this->getValidPath($this->currentDirectory);
        
        if ($targetPath === false) {
            $this->currentDirectory = '';
            $targetPath = $this->basePath;
        }

        // --- Aktionen Verarbeiten ---
        $this->handleActions($targetPath);

        // --- Rendern ---
        return $this->renderDirectory($targetPath);
    }

    // =================================================================
    // SETUP WIZARD
    // =================================================================
    private function handleSetupWizard() {
        if (isset($_POST['setup_password'])) {
            $hash = password_hash($_POST['setup_password'], PASSWORD_DEFAULT);
            $fileContent = file_get_contents(__FILE__);
            $newContent = preg_replace('/public \$passwordHash = \'\';/', 'public $passwordHash = \''.$hash.'\';', $fileContent, 1, $count);
            
            if ($count && is_writable(__FILE__)) {
                file_put_contents(__FILE__, $newContent);
                header("Location: ?");
                exit;
            } else {
                $this->msg = ['type' => 'warning', 'text' => 'Datei <b>index.php</b> ist nicht beschreibbar. Bitte trage folgenden Hash manuell in Zeile 14 ein:<br><code class="d-block mt-2 p-2 bg-dark text-light rounded">public $passwordHash = \''.$hash.'\';</code>'];
            }
        }
        
        return $this->buildWrapper('
            <div class="container mt-5"><div class="row justify-content-center"><div class="col-md-6">
            <div class="card shadow border-primary"><div class="card-header bg-primary text-white"><i class="bi bi-shield-lock"></i> Initiales Setup</div>
            <div class="card-body">
                ' . $this->renderMsg() . '
                <p>Willkommen! Es wurde noch kein Admin-Passwort festgelegt.</p>
                <form method="post">
                    <div class="mb-3"><input type="password" name="setup_password" class="form-control" placeholder="Neues Master-Passwort eingeben" required></div>
                    <button type="submit" class="btn btn-primary w-100">Passwort setzen & speichern</button>
                </form>
            </div></div></div></div></div>
        ');
    }

    // =================================================================
    // AKTIONEN (mit strikter Rechte-Trennung)
    // =================================================================
    private function handleActions($targetPath) {
        $verifyCsrf = function() {
            if (!isset($_REQUEST['csrf']) || $_REQUEST['csrf'] !== $this->csrfToken) die('CSRF Token ungültig!');
        };

        // 1. Herunterladen (Für Alle)
        if (isset($_GET['download'])) {
            $path = $this->getValidPath($_GET['download']);
            if ($path && is_file($path)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.basename($path).'"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            }
        }

        // 2. Editor API (Gäste nur Load, Admins Load & Save)
        if ($this->enableTextEditor && isset($_GET['editor_action'])) {
            $verifyCsrf();
            $path = $this->getValidPath($_POST['file'] ?? $_GET['file']);
            if (!$path || !is_file($path)) die(json_encode(['error' => 'Datei nicht gefunden']));
            
            if ($_GET['editor_action'] === 'load') {
                die(json_encode(['content' => file_get_contents($path)]));
            } elseif ($_GET['editor_action'] === 'save' && isset($_POST['content'])) {
                if (!$this->isAdmin()) die(json_encode(['error' => 'Keine Schreibrechte im Gast-Modus!']));
                file_put_contents($path, $_POST['content']);
                die(json_encode(['success' => true]));
            }
        }

        // ---> AB HIER NUR ADMINS <---
        if (!$this->isAdmin()) return; 

        // 3. AJAX Upload
        if ($this->enableUploads && isset($_POST['ajax_upload'])) {
            $verifyCsrf();
            header('Content-Type: application/json');
            
            if (empty($_FILES['files']['name'][0])) die(json_encode(['error' => 'Keine Datei empfangen']));
            
            $uploaded = 0;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            
            foreach ($_FILES['files']['name'] as $key => $name) {
                if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $tmpName = $_FILES['files']['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                
                if (in_array($ext, $this->blockedUploadExtensions)) continue;
                
                $safeName = preg_replace('/[^a-zA-Z0-9_.\-\säöüÄÖÜß]/', '_', basename($name));
                $destination = $targetPath . DIRECTORY_SEPARATOR . $safeName;
                
                if (move_uploaded_file($tmpName, $destination)) $uploaded++;
            }
            finfo_close($finfo);
            die(json_encode(['success' => true, 'count' => $uploaded]));
        }

        // 4. Ordner erstellen
        if ($this->enableDirectoryCreation && isset($_POST['create_dir'])) {
            $verifyCsrf();
            $dirName = preg_replace('/[^a-zA-Z0-9_.\-\säöüÄÖÜß]/', '_', $_POST['create_dir']);
            $newDirPath = $targetPath . DIRECTORY_SEPARATOR . $dirName;
            if (!file_exists($newDirPath)) {
                mkdir($newDirPath, 0755);
                $this->msg = ['type' => 'success', 'text' => 'Ordner erstellt.'];
            }
        }

        // 5. Umbenennen
        if ($this->enableRename && isset($_POST['rename_old']) && isset($_POST['rename_new'])) {
            $verifyCsrf();
            $oldPath = $this->getValidPath($_POST['rename_old']);
            $newName = preg_replace('/[^a-zA-Z0-9_.\-\säöüÄÖÜß]/', '_', basename($_POST['rename_new']));
            
            if ($oldPath && file_exists($oldPath)) {
                $newPath = dirname($oldPath) . DIRECTORY_SEPARATOR . $newName;
                if (!file_exists($newPath)) {
                    rename($oldPath, $newPath);
                    $this->msg = ['type' => 'success', 'text' => 'Erfolgreich umbenannt.'];
                } else {
                    $this->msg = ['type' => 'danger', 'text' => 'Name existiert bereits!'];
                }
            }
        }

        // 6. Löschen (Einzeln)
        if (isset($_GET['delete'])) {
            $verifyCsrf();
            $delPath = $this->getValidPath($_GET['delete']);
            if ($delPath) {
                if (is_file($delPath) && $this->enableFileDeletion) {
                    unlink($delPath);
                    $this->msg = ['type' => 'success', 'text' => 'Datei gelöscht.'];
                } elseif (is_dir($delPath) && $this->enableDirectoryDeletion) {
                    @rmdir($delPath);
                    $this->msg = ['type' => 'success', 'text' => 'Ordner gelöscht (falls leer).'];
                }
            }
        }

        // 7. Massenaktionen (Bulk Delete - ZIP ist für alle, Delete nur Admin)
        if ($this->enableBulkActions && isset($_POST['bulk_action']) && !empty($_POST['selected_files'])) {
            $verifyCsrf();
            $action = $_POST['bulk_action'];
            $files = $_POST['selected_files'];
            
            if ($action === 'delete') {
                $count = 0;
                foreach ($files as $relPath) {
                    $p = $this->getValidPath($relPath);
                    if ($p && is_file($p) && $this->enableFileDeletion) { unlink($p); $count++; }
                    elseif ($p && is_dir($p) && $this->enableDirectoryDeletion) { @rmdir($p); $count++; }
                }
                $this->msg = ['type' => 'success', 'text' => "$count Element(e) gelöscht."];
            }
            
            if ($action === 'zip' && class_exists('ZipArchive')) {
                // ... (ZIP Logik bleibt für Gäste erlaubt, siehe weiter unten)
            }
        }
        
        // ZIP für Gäste erlauben
        if (!$this->isAdmin() && $this->enableBulkActions && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'zip' && !empty($_POST['selected_files'])) {
             $verifyCsrf();
             $files = $_POST['selected_files'];
             if (class_exists('ZipArchive')) {
                $this->triggerZipDownload($files);
             }
        } elseif ($this->isAdmin() && $this->enableBulkActions && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'zip' && !empty($_POST['selected_files'])) {
             $this->triggerZipDownload($_POST['selected_files']);
        }
    }
    
    private function triggerZipDownload($files) {
        $zipName = 'Archive_' . date('Ymd_His') . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($files as $relPath) {
                $p = $this->getValidPath($relPath);
                if ($p && is_file($p)) $zip->addFile($p, basename($p));
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$zipName.'"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            unlink($zipPath);
            exit;
        }
    }

    // =================================================================
    // HELPER & RENDER FUNKTIONEN
    // =================================================================
    private function getValidPath($relPath) {
        $path = realpath($this->basePath . DIRECTORY_SEPARATOR . trim($relPath, '/\\'));
        if ($path !== false && strpos($path, $this->basePath) === 0) return $path;
        return false;
    }

    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function renderMsg() {
        if (empty($this->msg)) return '';
        return '<div class="alert alert-'.$this->msg['type'].' alert-dismissible fade show">'.$this->msg['text'].'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }

    private function renderLogin() {
        $guestBtn = '';
        if ($this->enableGuestMode) {
            if (empty($this->guestPasswordHash)) {
                $guestBtn = '<hr><form method="post"><button type="submit" name="login_guest" value="1" class="btn btn-outline-success w-100"><i class="bi bi-person-badge"></i> Als Gast betreten (Lesezugriff)</button></form>';
            } else {
                $guestBtn = '<div class="text-muted small mt-3 text-center">Gäste nutzen das Gast-Passwort.</div>';
            }
        }

        return $this->buildWrapper('
            <div class="container mt-5"><div class="row justify-content-center"><div class="col-md-4">
            <div class="card shadow"><div class="card-body">
                <h4 class="card-title text-center mb-4"><i class="bi bi-hdd-network"></i> Login</h4>
                ' . $this->renderMsg() . '
                <form method="post">
                    <div class="mb-3"><input type="password" name="login_password" class="form-control" placeholder="Passwort" required></div>
                    <button type="submit" class="btn btn-primary w-100">Einloggen</button>
                </form>
                '.$guestBtn.'
            </div></div></div></div></div>
        ');
    }

    private function renderDirectory($targetPath) {
        $items = scandir($targetPath);
        $directories = []; $files = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $targetPath . DIRECTORY_SEPARATOR . $item;
            $relPath = ltrim($this->currentDirectory . '/' . $item, '/');

            if (is_dir($fullPath)) {
                if (!in_array($item, $this->ignoredDirectories) && $item[0] !== '.') $directories[] = ['name' => $item, 'rel' => $relPath];
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (!in_array($ext, $this->ignoredFileExtensions) && !in_array($item, $this->ignoredFileNames) && $item[0] !== '.') {
                    $files[] = [
                        'name' => $item, 'rel' => $relPath, 'ext' => $ext,
                        'size' => $this->formatSize(filesize($fullPath)),
                        'modified' => filemtime($fullPath), 'type' => mime_content_type($fullPath),
                        'isText' => in_array($ext, ['txt', 'php', 'html', 'css', 'js', 'json', 'csv', 'ini', 'md', 'xml'])
                    ];
                }
            }
        }
        usort($directories, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        usort($files, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

        $html = '<div class="container-fluid py-4 px-4">';
        $html .= $this->renderMsg();
        
        $roleBadge = $this->isAdmin() ? '<span class="badge bg-danger ms-3 fs-6 align-middle"><i class="bi bi-shield-lock"></i> Admin</span>' : '<span class="badge bg-success ms-3 fs-6 align-middle"><i class="bi bi-person-badge"></i> Gast Modus</span>';

        // Header
        $html .= '<div class="d-flex justify-content-between align-items-center mb-4">';
        $html .= '<h2><i class="bi bi-hdd-network text-primary"></i> ' . htmlspecialchars($this->pageTitle) . $roleBadge . '</h2>';
        $html .= '<div>
                    <button class="btn btn-outline-secondary me-2" id="darkModeToggle" title="Dark Mode"><i class="bi bi-moon-stars"></i></button>';
        if ($this->passwordProtect) $html .= '<a href="?logout=1" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>';
        $html .= '</div></div>';

        // Breadcrumbs
        $html .= '<nav aria-label="breadcrumb"><ol class="breadcrumb p-3 bg-body-tertiary rounded shadow-sm">';
        $html .= '<li class="breadcrumb-item"><a href="?"><i class="bi bi-house"></i> Home</a></li>';
        $parts = array_filter(explode('/', $this->currentDirectory));
        $buildPath = '';
        foreach ($parts as $part) {
            $buildPath .= $part . '/';
            $html .= '<li class="breadcrumb-item"><a href="?dir=' . urlencode(rtrim($buildPath, '/')) . '">' . htmlspecialchars($part) . '</a></li>';
        }
        $html .= '</ol></nav>';

        // Upload & Ordner Erstellen (NUR ADMIN)
        if ($this->isAdmin() && ($this->enableUploads || $this->enableDirectoryCreation)) {
            $html .= '<div class="card shadow-sm mb-4"><div class="card-body row g-3 align-items-center">';
            if ($this->enableUploads) {
                $html .= '<div class="col-md-7">
                            <form id="ajaxUploadForm">
                                <div class="input-group">
                                    <input type="file" id="fileInput" class="form-control" multiple required>
                                    <button type="submit" class="btn btn-success"><i class="bi bi-cloud-arrow-up"></i> Upload</button>
                                </div>
                                <div class="progress mt-2 d-none" id="uploadProgress" style="height: 10px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%"></div>
                                </div>
                            </form>
                          </div>';
            }
            if ($this->enableDirectoryCreation) {
                $html .= '<div class="col-md-5">
                            <form method="post" class="input-group">
                                <input type="hidden" name="csrf" value="'.$this->csrfToken.'">
                                <input type="text" name="create_dir" class="form-control" placeholder="Neuer Ordnername..." required>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-folder-plus"></i> Erstellen</button>
                            </form>
                          </div>';
            }
            $html .= '</div></div>';
        }

        // Tabelle
        $html .= '<form method="post" id="bulkForm">';
        $html .= '<input type="hidden" name="csrf" value="'.$this->csrfToken.'">';
        $html .= '<input type="hidden" name="bulk_action" id="bulkActionInput" value="">';
        
        if ($this->enableBulkActions) {
            $html .= '<div class="mb-2 d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllCheckboxes()"><i class="bi bi-check2-square"></i> Alle (ab)wählen</button>';
            if ($this->isAdmin()) {
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger" onclick="submitBulk(\'delete\')"><i class="bi bi-trash"></i> Markierte löschen</button>';
            }
            $html .= '<button type="button" class="btn btn-sm btn-outline-info" onclick="submitBulk(\'zip\')"><i class="bi bi-file-zip"></i> Als ZIP laden</button>
                      </div>';
        }

        $html .= '<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
        $html .= '<thead class="table-light"><tr>';
        if ($this->enableBulkActions) $html .= '<th style="width: 40px;"></th>';
        $html .= '<th>Name</th><th class="text-end d-none d-md-table-cell">Größe</th><th class="text-end d-none d-md-table-cell">Datum</th><th class="text-end">Aktionen</th></tr></thead><tbody>';

        if ($this->currentDirectory !== '') {
            $upDir = dirname($this->currentDirectory);
            $upDir = ($upDir === '.' || $upDir === '\\') ? '' : $upDir;
            $cols = $this->enableBulkActions ? 5 : 4;
            $html .= '<tr><td colspan="'.$cols.'"><a href="?dir=' . urlencode($upDir) . '" class="text-decoration-none fw-bold"><i class="bi bi-arrow-90deg-up"></i> .. (Eine Ebene zurück)</a></td></tr>';
        }

        foreach ($directories as $dir) {
            $html .= '<tr>';
            if ($this->enableBulkActions) $html .= '<td><input type="checkbox" name="selected_files[]" value="'.htmlspecialchars($dir['rel']).'" class="form-check-input row-checkbox"></td>';
            $html .= '<td><a href="?dir=' . urlencode($dir['rel']) . '" class="text-decoration-none fw-bold text-body"><i class="bi bi-folder-fill text-warning fs-5 me-2"></i> ' . htmlspecialchars($dir['name']) . '</a></td>';
            $html .= '<td class="d-none d-md-table-cell"></td><td class="d-none d-md-table-cell"></td>';
            $html .= '<td class="text-end text-nowrap">';
            if ($this->isAdmin()) {
                if ($this->enableRename) $html .= '<button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="openRenameModal(\''.htmlspecialchars($dir['rel']).'\', \''.htmlspecialchars($dir['name']).'\')"><i class="bi bi-cursor-text"></i></button>';
                if ($this->enableDirectoryDeletion) $html .= '<a href="?delete=' . urlencode($dir['rel']) . '&csrf='.$this->csrfToken.'&dir=' . urlencode($this->currentDirectory) . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Verzeichnis löschen?\')"><i class="bi bi-trash"></i></a>';
            }
            $html .= '</td></tr>';
        }

        foreach ($files as $file) {
            $icon = $this->getFileIcon($file['ext']);
            $fileUrl = htmlspecialchars($file['rel']);
            
            $html .= '<tr>';
            if ($this->enableBulkActions) $html .= '<td><input type="checkbox" name="selected_files[]" value="'.$fileUrl.'" class="form-check-input row-checkbox"></td>';
            
            $previewAttrs = '';
            if (strpos($file['type'], 'image/') === 0) $previewAttrs = 'data-preview-type="image" data-preview-url="'.$fileUrl.'"';
            elseif (strpos($file['type'], 'video/') === 0) $previewAttrs = 'data-preview-type="video" data-preview-url="'.$fileUrl.'"';
            elseif (strpos($file['type'], 'audio/') === 0) $previewAttrs = 'data-preview-type="audio" data-preview-url="'.$fileUrl.'"';

            $html .= '<td><a href="' . $fileUrl . '" target="_blank" class="text-decoration-none text-body fw-medium dynamic-popover" ' . $previewAttrs . '><i class="' . $icon . ' fs-5 me-2"></i> ' . htmlspecialchars($file['name']) . '</a></td>';
            $html .= '<td class="text-end text-muted small d-none d-md-table-cell">' . $file['size'] . '</td>';
            $html .= '<td class="text-end text-muted small d-none d-md-table-cell">' . date('d.m.Y H:i', $file['modified']) . '</td>';
            
            $html .= '<td class="text-end text-nowrap">';
            $html .= '<a href="?download=' . urlencode($file['rel']) . '" class="btn btn-sm btn-outline-primary me-1" title="Herunterladen"><i class="bi bi-download"></i></a>';
            
            if ($this->enableTextEditor && $file['isText']) {
                $eIcon = $this->isAdmin() ? 'bi-pencil-square' : 'bi-eye';
                $eBtn  = $this->isAdmin() ? 'btn-outline-success' : 'btn-outline-secondary';
                $html .= '<button type="button" class="btn btn-sm '.$eBtn.' me-1" onclick="openEditor(\''.htmlspecialchars($file['rel']).'\')"><i class="bi '.$eIcon.'"></i></button>';
            }
            if ($this->isAdmin()) {
                if ($this->enableRename) $html .= '<button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="openRenameModal(\''.htmlspecialchars($file['rel']).'\', \''.htmlspecialchars($file['name']).'\')"><i class="bi bi-cursor-text"></i></button>';
                if ($this->enableFileDeletion)$html .= '<a href="?delete=' . urlencode($file['rel']) . '&csrf='.$this->csrfToken.'&dir=' . urlencode($this->currentDirectory) . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Datei löschen?\')"><i class="bi bi-trash"></i></a>';
            }
            $html .= '</td></tr>';
        }

        if (empty($directories) && empty($files)) {
            $cols =$this->enableBulkActions ? 5 : 4;
            $html .= '<tr><td colspan="'.$cols.'" class="text-center text-muted py-4">Dieses Verzeichnis ist leer.</td></tr>';
        }

        $html .= '</tbody></table></div></div></form></div>';
        $html .=$this->renderModals();

        return $this->buildWrapper($html);
    }

    private function renderModals() {
        $modals = '';
        if ($this->enableRename && $this->isAdmin()) {$modals .= '
            <div class="modal fade" id="renameModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf" value="'.$this->csrfToken.'">
                        <input type="hidden" name="rename_old" id="renameOldPath">
                        <div class="modal-header"><h5 class="modal-title">Umbenennen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body"><input type="text" name="rename_new" id="renameNewName" class="form-control" required></div>
                        <div class="modal-footer"><button type="submit" class="btn btn-primary">Speichern</button></div>
                    </form>
                </div></div>
            </div>';
        }
        if ($this->enableTextEditor) {
            $saveBtn =$this->isAdmin() ? '<button type="button" class="btn btn-success" onclick="saveEditor()">Speichern</button>' : '';
            $readOnly = $this->isAdmin() ? '' : 'readonly';$modals .= '
            <div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="editorTitle">Editor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body p-0">
                        <textarea id="editorTextarea" class="form-control border-0 rounded-0" style="height: 60vh; font-family: monospace; resize: none;" spellcheck="false" '.$readOnly.'></textarea>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <span id="editorStatus" class="text-muted small"></span>
                        <div><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button> '.$saveBtn.'</div>
                    </div>
                </div></div>
            </div>';
        }
        return $modals;
    }

    private function getFileIcon($ext) {$icons = [
            'pdf' => 'bi-file-earmark-pdf text-danger', 'zip' => 'bi-file-earmark-zip text-secondary', 'iso' => 'bi-disc text-secondary',
            'jpg' => 'bi-file-earmark-image text-success', 'png' => 'bi-file-earmark-image text-success',
            'mp4' => 'bi-file-earmark-play text-info', 'mp3' => 'bi-file-earmark-music text-warning',
            'txt' => 'bi-file-earmark-text text-secondary', 'php' => 'bi-filetype-php text-primary'
        ];
        return isset($icons[$ext]) ? $icons[$ext] : 'bi-file-earmark text-secondary';
    }

    private function buildWrapper($content) {$theme = isset($_COOKIE['theme']) &&$_COOKIE['theme'] === 'dark' ? 'dark' : 'light';
        return '<!DOCTYPE html>
<html lang="de" data-bs-theme="'.$theme.'">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>'.htmlspecialchars($this->pageTitle).'</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>.dynamic-popover{cursor:pointer;}.popover{max-width:320px;}.popover-body{padding:0.5rem;text-align:center;}.popover-body img, .popover-body video, .popover-body audio{max-width:100%;border-radius:4px;}</style>
</head>
<body class="bg-body-secondary">
    '.$content.'
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById("darkModeToggle")?.addEventListener("click", () => {
            const html = document.documentElement;
            const newTheme = html.getAttribute("data-bs-theme") === "dark" ? "light" : "dark";
            html.setAttribute("data-bs-theme", newTheme);
            document.cookie = "theme=" + newTheme + "; path=/; max-age=31536000";
        });

        function toggleAllCheckboxes() {
            const checkboxes = document.querySelectorAll(".row-checkbox");
            const state = checkboxes.length > 0 ? !checkboxes[0].checked : false;
            checkboxes.forEach(cb => cb.checked = state);
        }
        function submitBulk(action) {
            if(document.querySelectorAll(".row-checkbox:checked").length === 0) return alert("Keine Dateien ausgewählt!");
            if(action === "delete" && !confirm("Ausgewählte wirklich löschen?")) return;
            document.getElementById("bulkActionInput").value = action;
            document.getElementById("bulkForm").submit();
        }

        let renameModal;
        function openRenameModal(oldPath, oldName) {
            if(!renameModal) renameModal = new bootstrap.Modal(document.getElementById("renameModal"));
            document.getElementById("renameOldPath").value = oldPath;
            document.getElementById("renameNewName").value = oldName;
            renameModal.show();
        }

        let editorModal, currentEditFile = "";
        function openEditor(filePath) {
            if(!editorModal) editorModal = new bootstrap.Modal(document.getElementById("editorModal"));
            currentEditFile = filePath;
            document.getElementById("editorTitle").innerText = "Lade: " + filePath.split("/").pop();
            document.getElementById("editorTextarea").value = "Lade...";
            document.getElementById("editorStatus").innerText = "";
            editorModal.show();

            let fd = new FormData(); fd.append("csrf", "'.$this->csrfToken.'"); fd.append("file", filePath);
            fetch("?editor_action=load", { method: "POST", body: fd }).then(r => r.json()).then(d => {
                if(d.error) document.getElementById("editorTextarea").value = "Fehler: " + d.error;
                else { document.getElementById("editorTextarea").value = d.content; document.getElementById("editorTitle").innerText = filePath.split("/").pop(); }
            });
        }
        function saveEditor() {
            document.getElementById("editorStatus").innerText = "Speichere...";
            let fd = new FormData(); fd.append("csrf", "'.$this->csrfToken.'"); fd.append("file", currentEditFile); fd.append("content", document.getElementById("editorTextarea").value);
            fetch("?editor_action=save", { method: "POST", body: fd }).then(r => r.json()).then(d => {
                if(d.success) document.getElementById("editorStatus").innerHTML = "<span class=\'text-success\'><i class=\'bi bi-check\'></i> Gespeichert</span>";
                else document.getElementById("editorStatus").innerText = "Fehler: Keine Berechtigung.";
            });
        }

        const uploadForm = document.getElementById("ajaxUploadForm");
        if(uploadForm) {
            uploadForm.addEventListener("submit", function(e) {
                e.preventDefault();
                const fileInput = document.getElementById("fileInput");
                if(fileInput.files.length === 0) return;

                const fd = new FormData();
                for(let i=0; i<fileInput.files.length; i++) fd.append("files[]", fileInput.files[i]);
                fd.append("ajax_upload", "1"); fd.append("csrf", "'.$this->csrfToken.'");

                const pw = document.getElementById("uploadProgress");
                const pb = pw.querySelector(".progress-bar");
                pw.classList.remove("d-none"); pb.style.width = "0%";
                
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "?dir=' . urlencode($this->currentDirectory) . '", true);
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) { const pc = Math.round((e.loaded / e.total) * 100); pb.style.width = pc + "%"; pb.innerText = pc + "%"; }
                };
                xhr.onload = function() { if (xhr.status === 200) location.reload(); else { alert("Fehler!"); pw.classList.add("d-none"); } };
                xhr.send(fd);
            });
        }

        document.addEventListener("DOMContentLoaded", function(){
            document.querySelectorAll(".dynamic-popover[data-preview-type]").forEach(el => {
                new bootstrap.Popover(el, {
                    trigger: "hover", html: true, placement: "right",
                    content: function() {
                        const t = el.getAttribute("data-preview-type"); const u = el.getAttribute("data-preview-url");
                        if(t === "image") return `<img src="${u}">`;
                        if(t === "video") return `<video src="${u}" autoplay muted loop style="width: 250px;"></video>`;
                        if(t === "audio") return `<audio src="${u}" controls autoplay style="width: 250px;"></audio>`;
                        return "";
                    }
                });
            });
        });
    </script>
</body>
</html>';
    }
}

$listing = new DirectoryListing();
echo $listing->run();
?>