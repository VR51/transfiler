<?php
// Security: Always sanitize and validate input!
// Ensure only authorized users can access this script.

/**
* Transfiler: Differ v1.0.0
* Copyight 2025 VR51, Lee Hodson, Gemini Flash 2
* GNU General Public License v3.0
*
* 1. Use this script to compare two file index logs.
* 2. Create an index of server files and directories on Server One and on Server Two (usually after transfer) using index_files.php or index_files.sh.
* 3. Place this script on your web server then access it at [domain]/compare_logs.php.
* 4. Fill in the form.
* 7. Click the Compare Logs button.
* 8. Download the diff log and analyise it in a spreadsheet.
* 9. Delete this script and its log files from your web server after use. If you leave it in place someone else might use it to force your server to continuously download files which would take up a lot of resources.
* 10. This script must be saved as compare_logs.php otherwise the AJAX callback address hardcoded into the script must be amended.
*/

//Configuration settings
$allowed_file_types = ['text/csv', 'text/plain'];

// Function to compare the logs
function compare_logs($log_file_a, $log_file_b, $allowed_file_types) {

    // Security: Verify file types
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type_a = finfo_file($finfo, $log_file_a);
    $mime_type_b = finfo_file($finfo, $log_file_b);
    finfo_close($finfo);

    if (!in_array($mime_type_a, $allowed_file_types) || !in_array($mime_type_b, $allowed_file_types)) {
        return ["status" => "error", "message" => "Invalid file type. Only CSV or plain text files are allowed."];
    }

    $log_a_data = [];
    $log_b_data = [];

    // Read Log A
    if (($handle = fopen($log_file_a, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header row
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) === 4) {
                $key = $data[1] . $data[0]; // Use relative path + name as key
                $log_a_data[$key] = ["name" => $data[0], "relative_path" => $data[1], "type" => $data[2], "size" => $data[3]];
            }
        }
        fclose($handle);
    } else {
        return ["status" => "error", "message" => "Unable to open Log A."];
    }

    // Read Log B
    if (($handle = fopen($log_file_b, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header row
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) === 4) {
                $key = $data[1] . $data[0]; // Use relative path + name as key
                $log_b_data[$key] = ["name" => $data[0], "relative_path" => $data[1], "type" => $data[2], "size" => $data[3]];
            }
        }
        fclose($handle);
    } else {
        return ["status" => "error", "message" => "Unable to open Log B."];
    }

    $differences = [];
    $differences[] = "Name,Relative Path,Type,Size,Source Log"; // CSV Header

    // Find differences
    foreach ($log_a_data as $key => $entry) {
        if (!isset($log_b_data[$key])) {
            $differences[] = '"' . str_replace('"', '""', $entry["name"]) . '","' . $entry["relative_path"] . '","' . $entry["type"] . '","' . $entry["size"] . '","A"';
        } else {
            //Check for size differences
            if ($entry["size"] != $log_b_data[$key]["size"] && $entry["type"] == "file") {
                $size_diff = $log_b_data[$key]["size"] - $entry["size"];
                $differences[] = '"' . str_replace('"', '""', $entry["name"]) . '","' . $entry["relative_path"] . '","' . $entry["type"] . '","' . $size_diff . '","Size Diff"';
            }
            //Check for Type differences
            if($entry["type"] != $log_b_data[$key]["type"]) {
                $differences[] = '"' . str_replace('"', '""', $entry["name"]) . '","' . $entry["relative_path"] . '","' . "Type Diff" . '","' . "" . '","Type Diff"';
            }
        }
    }

    foreach ($log_b_data as $key => $entry) {
        if (!isset($log_a_data[$key])) {
            $differences[] = '"' . str_replace('"', '""', $entry["name"]) . '","' . $entry["relative_path"] . '","' . $entry["type"] . '","' . $entry["size"] . '","B"';
        }
    }

    $filename = "differences_" . date("Ymd_His") . "_" . uniqid() . ".csv";
    $filepath = __DIR__ . "/" . $filename;
    file_put_contents($filepath, implode("\n", $differences));

    return ["status" => "success", "message" => "Differences file created: " . $filename, "filename" => $filename];
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Security:  Validate file uploads
    if (isset($_FILES["log_file_a"]) && $_FILES["log_file_a"]["error"] == UPLOAD_ERR_OK && isset($_FILES["log_file_b"]) && $_FILES["log_file_b"]["error"] == UPLOAD_ERR_OK) {
        $log_file_a = $_FILES["log_file_a"]["tmp_name"];
        $log_file_b = $_FILES["log_file_b"]["tmp_name"];

        $result = compare_logs($log_file_a, $log_file_b, $allowed_file_types);

        echo json_encode($result);
        exit;
    } else {
        echo json_encode(["status" => "error", "message" => "File upload error."]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Log File Comparator</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#comparatorForm").submit(function(e) {
                e.preventDefault();
                var formData = new FormData(this);

                $.ajax({
                    type: "POST",
                    url: "compare_logs.php",
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: "json",
                    success: function(data) {
                        if (data.status === "success") {
                            $("#result").html("<p>Differences file created: <a href='" + data.filename + "'>" + data.filename + "</a></p>");
                        } else {
                            $("#result").html("<p>Error: " + data.message + "</p>");
                        }
                    },
                    error: function(xhr, status, error) {
                        $("#result").html("<p>AJAX Error: " + error + "</p>");
                    }
                });
            });
        });
    </script>
</head>
<body>
    <h1>Log File Comparator</h1>
    <form id="comparatorForm" enctype="multipart/form-data">
        Log File A: <input type="file" id="log_file_a" name="log_file_a"><br><br>
        Log File B: <input type="file" id="log_file_b" name="log_file_b"><br><br>
        <button type="submit">Compare Logs</button>
    </form>
    <div id="result"></div>
</body>
</html>
