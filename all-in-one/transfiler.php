<?php
// Security:  Always sanitize and validate input!
// Ensure only authorized users can access this script.

// Configuration Settings (Can be adjusted via the web form)
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv']; // Example allowed extensions
$enable_extension_check = true;  // Set to false to disable extension check
$max_file_size = 10485760; // 10MB in bytes
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv']; // Example mime types

// New:  Excluded file extensions
$excluded_extensions = ['php', 'exe', 'sh'];

//List of allowed file extensions for the log comparitor
$allowed_file_types = ['text/csv', 'text/plain'];

// *** Functions (Indexer, Downloader, Comparator) ***

//Function used to download from a remote location
function download_csv($remote_url){
  $ch = curl_init($remote_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // For HTTPS (careful with this in production)
  curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a timeout
  $csv_content = curl_exec($ch);

  if (curl_errno($ch)) {
      $err_message = "Failed to download CSV file: " . curl_error($ch);
      curl_close($ch);
      return $err_message;
  }
  curl_close($ch);

  return $csv_content;
}

// Function to sanitize filename based on allowed extensions
function sanitize_filename($filename, $allowed_extensions) {
    // Get the file extension
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Check if the extension is in the allowed list
    if (!in_array($extension, $allowed_extensions)) {
        return false; // Reject the file if the extension is not allowed
    }

    // Sanitize the filename (remove special characters, spaces, etc.)
    $sanitized_filename = preg_replace("/[^a-zA-Z0-9._-]/", "", $filename);

    return $sanitized_filename; // Return the sanitized filename
}

// Function to safely put content to file, including error handling
function safe_file_put_contents($filename, $content, $flags = 0) {
    try {
        $result = file_put_contents($filename, $content, $flags);
        if ($result === false) {
            $error = error_get_last();
            error_log("Failed to write to file " . $filename . ": " . $error['message']);
            return false;
        }
        return $result;
    } catch (Exception $e) {
        error_log("Exception occurred while writing to file " . $filename . ": " . $e->getMessage());
        return false;
    }
}

// Function to generate CSV data for the indexer
function generate_csv_data($directory, $allowed_extensions, $excluded_extensions, $enable_extension_check) {
    $data = [];
    $data[] = "Name,Relative Path,Type,Size"; // CSV Header
    $dir_iterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        $relative_path = str_replace($directory, '.', $item->getPathname()); // Relative Path
        $name = $item->getFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        //Check for disallowed file
        if (in_array($extension, $excluded_extensions)) {
          continue; // Skip to the next file
        }

        // Check file extension restrictions
        if ($enable_extension_check && $item->isFile()) {
            if (!in_array($extension, $allowed_extensions)) {
                continue; // Skip to the next file
            }
        }

        if ($item->isDir()) {
            $type = "directory";
            $size = ""; // Not calculating directory size for speed
        } elseif ($item->isFile()) {
            $type = "file";
            $size = $item->getSize();
        } else {
            $type = "unknown";
            $size = "";
        }
        $data[] = '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"'; // CSV format with escaping
    }
    return implode("\n", $data);
}

// Function to download files
function download_files($csv_file, $remote_url, $allowed_extensions, $excluded_extensions, $enable_extension_check) {
    $lines = explode("\n", $csv_file);
    $file_list = [];
    $downloaded = [];

    foreach ($lines as $line) {
        $data = str_getcsv($line);
        if (count($data) === 4) {
            list($name, $relative_path, $type, $size) = $data;

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, $excluded_extensions) && $type == "file") {
              continue; // Skip to the next file
            }

            // Add to the list of items to be downloaded.
            if ($type === "directory") {
              mkdir($relative_path, 0777, true);
              $downloaded[] = "Created Directory: $relative_path";
            }

            if ($type === "file") {
              if ($enable_extension_check) {
                  $sanitized_filename = sanitize_filename($name, $allowed_extensions);
                  if ($sanitized_filename === false) {
                      continue; // Skip to the next file
                  }
              }

              $file_url = rtrim($remote_url, '/') . '/' . $relative_path; // Create full URL
              //Check for existing files
              if (!file_exists($relative_path)) {
                // Download the file
                $file_content = download_csv($file_url); //Download the file.
                safe_file_put_contents($relative_path, $file_content);
                $downloaded[] = "File Downloaded: $relative_path";
              } else {
                $downloaded[] = "File Already Exists: $relative_path";
              }
            }
        }
    }
    return $downloaded;
}

// Function to compare logs
function compare_logs($log_file_a_content, $log_file_b_content, $allowed_file_types) {
        $log_a_data = [];
        $log_b_data = [];

        #Helper script to clean duplicate files
        function csvToArray($csvString) {
          $rows = array_map('str_getcsv', explode("\n", trim($csvString)));
          $header = array_shift($rows);

          $csv = array();
          foreach($rows as $row) {
            if (empty($row)) continue;

            $csv[] = array_combine($header, $row);
          }
          return $csv;
        }

        #Use the helper script to turn a long string into array.
        $log_a_data_csv = csvToArray($log_file_a_content);
        $log_b_data_csv = csvToArray($log_file_b_content);

        foreach($log_a_data_csv as $key => $arr){
          #Check if the keys exist, otherwise continue.
          if(!array_key_exists("Relative Path", $arr) || !array_key_exists("Name", $arr) || !array_key_exists("Type", $arr) || !array_key_exists("Size", $arr)){
              continue;
          }
          $log_a_data[$arr["Relative Path"] . $arr["Name"]] = $arr;
        }

        foreach($log_b_data_csv as $key => $arr){
          #Check if the keys exist, otherwise continue.
          if(!array_key_exists("Relative Path", $arr) || !array_key_exists("Name", $arr) || !array_key_exists("Type", $arr) || !array_key_exists("Size", $arr)){
              continue;
          }
          $log_b_data[$arr["Relative Path"] . $arr["Name"]] = $arr;
        }

        $differences = [];
        $differences[] = "Name,Relative Path,Type,Size,Source Log"; // CSV Header

        // Find differences
        foreach ($log_a_data as $key => $entry) {
            if (!isset($log_b_data[$key])) {
                $differences[] = '"' . str_replace('"', '""', $entry["Name"]) . '","' . $entry["Relative Path"] . '","' . $entry["Type"] . '","' . $entry["Size"] . '","A"';
            } else {
                //Check for size differences
                if ($entry["Size"] != $log_b_data[$key]["Size"] && $entry["Type"] == "file") {
                    $size_diff = $log_b_data[$key]["Size"] - $entry["Size"];
                    $differences[] = '"' . str_replace('"', '""', $entry["Name"]) . '","' . $entry["Relative Path"] . '","' . $entry["Type"] . '","' . $size_diff . '","Size Diff"';
                }
                //Check for Type differences
                if($entry["Type"] != $log_b_data[$key]["Type"]) {
                    $differences[] = '"' . str_replace('"', '""', $entry["Name"]) . '","' . $entry["Relative Path"] . '","' . "Type Diff" . '","' . "" . '","Type Diff"';
                }
            }
        }

        foreach ($log_b_data as $key => $entry) {
            if (!isset($log_a_data[$key])) {
                $differences[] = '"' . str_replace('"', '""', $entry["Name"]) . '","' . $entry["Relative Path"] . '","' . $entry["Type"] . '","' . $entry["Size"] . '","B"';
            }
        }

        $filename = "differences_" . date("Ymd_His") . "_" . uniqid() . ".csv";
        $filepath = __DIR__ . "/" . $filename;
        safe_file_put_contents($filepath, implode("\n", $differences));

        return "Differences file created: " . $filename;
}

// *** Main Control Structure ***
$action = isset($_POST["action"]) ? $_POST["action"] : "main"; // Default action
$result = "";

switch ($action) {
    case "index":
        $path = isset($_POST["path"]) ? realpath($_POST["path"]) : __DIR__;
        if (!$path) {
            $result = "Invalid path.";
            break;
        }

        $csv_data = generate_csv_data($path, $allowed_extensions, $excluded_extensions, $enable_extension_check);
        $filename = "index_" . date("Ymd_His") . "_" . uniqid() . ".csv";
        $filepath = __DIR__ . "/" . $filename;
        safe_file_put_contents($filepath, $csv_data);

        $result = "CSV file created: <a href='" . $filename . "'>" . $filename . "</a>";
        break;

    case "download":
        $csv_file = isset($_POST["csv_file"]) ? $_POST["csv_file"] : '';
        $remote_url = isset($_POST["remote_url"]) ? $_POST["remote_url"] : '';

        #Validate the download data.
        if (!filter_var($remote_url, FILTER_VALIDATE_URL)) {
          $result = "Remote Url not in proper format";
          break;
        }

        $csv_data = download_csv($remote_url . "/" . $csv_file);
        $data = download_files($csv_data, $remote_url, $allowed_extensions, $excluded_extensions, $enable_extension_check);
        $result = "<div class='php-result'>";
        foreach($data as $key => $value){
          $result .= "<p>$value</p>";
        }
        $result .= "</div>";
        break;

    case "compare":

        $log_file_a = isset($_FILES["log_file_a"]["tmp_name"]) ? $_FILES["log_file_a"]["tmp_name"] : '';
        $log_file_b = isset($_FILES["log_file_b"]["tmp_name"]) ? $_FILES["log_file_b"]["tmp_name"] : '';

        //Check that the files exist before moving on.
        $a = isset($_FILES["log_file_a"]["tmp_name"]) ? file_get_contents($_FILES["log_file_a"]["tmp_name"]) : '';
        $b = isset($_FILES["log_file_b"]["tmp_name"]) ? file_get_contents($_FILES["log_file_b"]["tmp_name"]) : '';

        $result = compare_logs($a, $b, $allowed_file_types);

        $result = "<div>$result</div>";
        break;

    case "main":
    default:
        // Display the main form (see HTML below)
        break;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Transfiler - File Migration Tool</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <style>
        body { font-family: sans-serif; }
        .container { width: 80%; margin: 0 auto; }
        h1 { text-align: center; }
        form { margin-bottom: 20px; padding: 10px; border: 1px solid #ccc; }
        input[type="text"], input[type="file"] { width: 100%; padding: 5px; margin-bottom: 10px; }
        button { background-color: #4CAF50; color: white; padding: 10px 20px; border: none; cursor: pointer; }
        #result { margin-top: 20px; padding: 10px; border: 1px solid #ccc; }
        #functions {margin-bottom: 20px; padding: 10px; border: 1px solid #ccc;}
        .php-result {margin-bottom: 20px; padding: 10px; border: 1px solid #ccc;}
    </style>
    <script>
        $(document).ready(function() {
            // Generic form submission function
            function submitForm(formId, action) {
                $("#result").empty(); // Clear previous results
                var formData = new FormData($(formId)[0]);
                formData.append("action", action);

                $.ajax({
                    type: "POST",
                    url: "transfiler.php",
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: "html",
                    success: function(data) {
                        $("#result").html(data);
                    },
                    error: function(xhr, status, error) {
                        $("#result").html("<p>AJAX Error: " + error + "</p>");
                    }
                });
            }

            $("#indexerForm").submit(function(e) {
                e.preventDefault();
                submitForm("#indexerForm", "index");
            });

            $("#downloaderForm").submit(function(e) {
                e.preventDefault();
                submitForm("#downloaderForm", "download");
            });

            $("#comparatorForm").submit(function(e) {
                e.preventDefault();
                submitForm("#comparatorForm", "compare");
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <h1>Transfiler - File Migration Tool</h1>
        <div id="functions">
          <h2>Function List</h2>
          <h3>Indexer</h3>
          <p>To download, click on the Download Button.</p>
          <h3>Downloader</h3>
          <p>To view the differences, click on the file differences form. This script will be a single report of file differences.</p>
          <h3>Comparator</h3>
        </div>
        <form id="indexerForm" enctype="multipart/form-data">
            <h2>Generate File Index</h2>
            Path to index: <input type="text" id="path" name="path" value="<?php echo htmlspecialchars(__DIR__); ?>"><br>
            <button type="submit">Generate Index</button>
        </form>

        <form id="downloaderForm" enctype="multipart/form-data">
            <h2>Download Files</h2>
            CSV File URL: <input type="text" id="csv_file" name="csv_file"><br>
            Remote URL: <input type="text" id="remote_url" name="remote_url"><br>
            <button type="submit">Download Files</button>
        </form>

        <form id="comparatorForm" enctype="multipart/form-data">
            <h2>Compare Log Files</h2>
            Log File A: <input type="file" id="log_file_a" name="log_file_a"><br>
            Log File B: <input type="file" id="log_file_b" name="log_file_b"><br>
            <button type="submit">Compare Logs</button>
        </form>

        <div id="result">
            <?php echo $result; ?>
        </div>
    </div>
</body>
</html>
