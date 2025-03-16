#!/bin/bash

# Transfiler: A tool for indexing, downloading, and comparing website files.

# --- Configuration (Now via command-line arguments) ---

# Function to display usage instructions
usage() {
  echo "Usage: $0 [command] [options]"
  echo ""
  echo "Commands:"
  echo "  index  - Generate a file index."
  echo "  download - Download files based on an index."
  echo "  compare - Compare two index files."
  echo ""
  echo "Options:"
  echo "  --path <directory>       (index) Path to index."
  echo "  --allowed-ext <ext1,ext2,...> (index, download) Allowed file extensions (comma-separated)."
  echo "  --disallowed-ext <ext1,ext2,...> (index, download) Disallowed file extensions (comma-separated)."
  echo "  --allowed-dir <dir1,dir2,...> (index) Allowed directories (comma-separated)."
  echo "  --disallowed-dir <dir1,dir2,...> (index) Disallowed directories (comma-separated)."
  echo "  --csv-file <filename>    (download, compare) CSV file name."
  echo "  --remote-url <URL>         (download) Remote URL for downloads."
  echo "  --log-file-a <filename>  (compare) First log file."
  echo "  --log-file-b <filename>  (compare) Second log file."
  echo "  --output <filename>      (compare) Output CSV file."
  echo "  --help                   Display this help message."
  echo ""
  echo "Examples:"
  echo "  $0 index --path /var/www/html --allowed-ext html,css,js"
  echo "  $0 download --csv-file index.csv --remote-url http://example.com"
  echo "  $0 compare --log-file-a log1.csv --log-file-b log2.csv --output differences.csv"
  exit 1
}

# Function to generate a unique filename
generate_unique_filename() {
  while true; do
    filename="$1_$(date +%Y%m%d_%H%M%S)_$RANDOM.csv"
    if [[ ! -f "$filename" ]]; then
      echo "$filename"
      return
    fi
  done
}

# --- Handle Command-Line Arguments ---
COMMAND=""
PATH=""
ALLOWED_EXT=""
DISALLOWED_EXT=""
ALLOWED_DIR=""
DISALLOWED_DIR=""
CSV_FILE=""
REMOTE_URL=""
LOG_FILE_A=""
LOG_FILE_B=""
OUTPUT_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    index|download|compare)
      COMMAND="$1"
      ;;
    --path)
      PATH="$2"
      shift
      ;;
    --allowed-ext)
      ALLOWED_EXT="$2"
      shift
      ;;
    --disallowed-ext)
      DISALLOWED_EXT="$2"
      shift
      ;;
    --allowed-dir)
      ALLOWED_DIR="$2"
      shift
      ;;
    --disallowed-dir)
      DISALLOWED_DIR="$2"
      shift
      ;;
    --csv-file)
      CSV_FILE="$2"
      shift
      ;;
    --remote-url)
      REMOTE_URL="$2"
      shift
      ;;
    --log-file-a)
      LOG_FILE_A="$2"
      shift
      ;;
    --log-file-b)
      LOG_FILE_B="$2"
      shift
      ;;
    --output)
      OUTPUT_FILE="$2"
      shift
      ;;
    --help)
      usage
      exit 0
      ;;
    *)
      echo "Error: Invalid option: $1"
      usage
      exit 1
      ;;
  esac
  shift
done

# --- Dispatch to Functions Based on Command ---

# Function to index files
index_files() {
  if [[ -z "$PATH" ]]; then
    echo "Error: --path is required for the index command."
    usage
    exit 1
  fi

  # Configuration Settings
  ALLOWED_EXTENSIONS=(${ALLOWED_EXT//,/ })
  DISALLOWED_EXTENSIONS=(${DISALLOWED_EXT//,/ })
  ALLOWED_DIRECTORIES=(${ALLOWED_DIR//,/ })
  DISALLOWED_DIRECTORIES=(${DISALLOWED_DIR//,/ })

  # Get the script's directory
  SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
  cd "$SCRIPT_DIR"

  # Generate a unique CSV filename
  CSV_FILE=$(generate_unique_filename "index")

  # Create the CSV header
  echo "Name,Relative Path,Type,Size" > "$CSV_FILE"

  # Function to check if a directory is allowed
  is_directory_allowed() {
    local relative_path="$1"

    if [[ ${#ALLOWED_DIRECTORIES[@]} -gt 0 ]]; then
      local is_allowed=0
      for allowed_dir in "${ALLOWED_DIRECTORIES[@]}"; do
        if [[ "$relative_path" == "./$allowed_dir"* ]]; then  #Check if it *starts* with the allowed directory
          is_allowed=1
          break
        fi
      done
      if [[ "$is_allowed" -eq 0 ]]; then
        return 1  # Not allowed
      fi
    fi
    return 0  # Allowed
  }

  # Function to check if a directory is disallowed
  is_directory_disallowed() {
    local relative_path="$1"

    for disallowed_dir in "${DISALLOWED_DIRECTORIES[@]}"; do
      if [[ "$relative_path" == "./$disallowed_dir"* ]]; then
        return 1  # Disallowed
      fi
    done
    return 0 # Not disallowed
  }

  # Function to check if a file extension is allowed
  is_extension_allowed() {
    local filename="$1"
    local extension="${filename##*.}"  # Get file extension

    if [[ ${#ALLOWED_EXTENSIONS[@]} -gt 0 ]]; then
      local is_allowed=0
      for allowed_ext in "${ALLOWED_EXTENSIONS[@]}"; do
        if [[ "$extension" == "$allowed_ext" ]]; then
          is_allowed=1
          break
        fi
      done
      if [[ "$is_allowed" -eq 0 ]]; then
        return 1  # Not allowed
      fi
    fi
    return 0 # Allowed
  }

  # Function to check if a file extension is disallowed
  is_extension_disallowed() {
    local filename="$1"
    local extension="${filename##*.}"  # Get file extension

    for disallowed_ext in "${DISALLOWED_EXTENSIONS[@]}"; do
      if [[ "$extension" == "$disallowed_ext" ]]; then
        return 1  # Disallowed
      fi
    done
    return 0 # Allowed
  }

  # Use 'find' to walk the directory structure and generate the CSV data
  find "$PATH" -print0 | while IFS= read -r -d $'\0' item; do
    RELATIVE_PATH="./${item#./}" # Handle potential leading "./"
    NAME="$(basename "$item")"

    # Skip disallowed directories
    if [[ -d "$item" ]] && is_directory_disallowed "$RELATIVE_PATH"; then
      continue
    fi

    # Skip not allowed directories
    if [[ -d "$item" ]] && ! is_directory_allowed "$RELATIVE_PATH"; then
      continue
    fi

    # Check file extension restrictions
    if [[ -f "$item" ]]; then
      if is_extension_allowed "$NAME"; then
        continue
      fi

      if is_extension_disallowed "$NAME"; then
        continue
      fi
    fi

    if [ -d "$item" ]; then
      TYPE="directory"
      SIZE=""  # Size is not relevant for directories in this context
    elif [ -f "$item" ]; then
      TYPE="file"
      SIZE="$(stat -c %s "$item")"
    else
      TYPE="unknown"
      SIZE=""
    fi

    # Escape commas in file names to prevent CSV parsing issues
    NAME="${NAME//,/','}"

    echo "$NAME,$RELATIVE_PATH,$TYPE,$SIZE" >> "$CSV_FILE"
  done

  echo "CSV index file created: $CSV_FILE in $SCRIPT_DIR"
}

# Function to download files
download_files() {
  if [[ -z "$CSV_FILE" || -z "$REMOTE_URL" ]]; then
    echo "Error: --csv-file and --remote-url are required for the download command."
    usage
    exit 1
  fi

  # Status Feedback - Once execution is over.
  files_copied_count=0
  directories_created_count=0
  files_failed_count=0

  # Before moving on check for continue_download.
  # Make sure logs exist.
  if [[ ! -f "downloaded_files.log" ]]; then
    echo "Name,Relative Path,Type,Size" > "downloaded_files.log"
  fi

  if [[ ! -f "failed_files.log" ]]; then
    echo "Name,Relative Path,Type,Size" > "failed_files.log"
  fi

  if [[ ! -f "ignored_files.log" ]]; then
    echo "Name,Relative Path,Type,Size" > "ignored_files.log"
  fi

  # Function to sanitize filename based on allowed extensions
  sanitize_filename() {
      filename="$1"
      # Get the file extension
      extension="${filename##*.}"

      # Check if the extension is in the allowed list
      is_allowed="0"
      for ext in "${ALLOWED_EXT[@]}"; do
          if [[ "$extension" == "$ext" ]]; then
              is_allowed="1"
              break
          fi
      done

      if [[ "$is_allowed" == "0" ]]; then
          echo ""  # Reject the file if the extension is not allowed
      else
        echo "$filename"
      fi
      # Sanitize the filename (remove special characters, spaces, etc.)
      # sanitized_filename=$(echo "$filename" | sed 's/[^a-zA-Z0-9._-]//g')
      # echo "$sanitized_filename"
  }

  # Function to download a file
  download_file() {
    local remote_url="$1"
    local relative_path="$2"
    local target_file="$3"

    # Download the file using curl and check the return code
    curl -s -o "$target_file" "$remote_url/$relative_path"
    if [ $? -ne 0 ]; then
      echo "Failed to download file: $name"
      return 1  # Failure
    fi
    return 0  # Success
  }

  # Function to check if a file extension is allowed
  is_extension_disallowed() {
      local filename="$1"
      local extension="${filename##*.}"  # Get file extension

      for disallowed_ext in "${DISALLOWED_EXTENSIONS[@]}"; do
        if [[ "$extension" == "$disallowed_ext" ]]; then
          return 1 # Allowed
        fi
      done
      return 0 # Disallowed
  }

  is_file_downloaded(){
    log_file="$1"
    file_name="$2"
    relative_path="$3"

    if [[ -f "$log_file" ]]; then
      while IFS=, read -r name rel_path _ _; do
        if [[ "$name" == "$file_name" && "$rel_path" == "$relative_path" ]]; then
          return 0
        fi
      done < "$log_file"
    fi
    return 1
  }

  # Retrieve the CSV file from remote server - and start download actions.
  csvData="$(curl -s "$REMOTE_URL/$CSV_FILE")"

  while IFS=, read -r name relative_path type size; do
    if [[ "$type" == "file" ]] && [[ -n "$name" ]]; then
      # Sanitize filenames and create download location for file.
      target_file=$(escape_filename "$relative_path")

      if is_extension_disallowed "$name"; then
        echo "Skipping disallowed file $relative_path with extension: $extension"
        continue
      fi

      # Check the extensions
      if [[ "$ENABLE_EXTENSION_CHECK" -eq 1 ]]; then
        check=$(sanitize_filename "$name" "${ALLOWED_EXTENSIONS[@]}")

        # If file does not contain the extension, then skip file.
        if [[ -z "$check" ]]; then
          echo "Skipping disallowed file $relative_path with extension: $extension"
          continue
        fi
      fi

      # Check to prevent duplicate files to exist.
      checkDownloaded=$(is_file_downloaded "$DOWNLOADED_LOG_FILE" "$name" "$relative_path")
      if [[ "$checkDownloaded" -eq 0 ]]; then
        echo "SKIPPING DOWNLOAD. File Already Downloaded!"
        continue;
      fi

      # Download actions
      if [[ ! -f "$relative_path" ]]; then
        # Start the download.
        downloaded=$(download_file "$REMOTE_URL" "$relative_path" "$target_file")
        if [[ "$downloaded" -eq 0 ]]; then
          # Add success count
          files_copied_count=$((files_copied_count + 1))
          echo "Downloaded $relative_path"

          # Start Adding logs to files.
          echo "$name,$relative_path,$type,$size" >> "$DOWNLOADED_LOG_FILE"
        else
          # Count the failed files.
          files_failed_count=$((files_failed_count + 1))
          echo "File $relative_path failed. Check the logs."

          # Save failed result in logs.
          echo "$name,$relative_path,$type,$size" >> "$FAILED_LOG_FILE"
        fi
      fi
    elif [[ "$type" == "directory" ]]; then
      # Check Directory to create if not already present.
      if [[ ! -d "$relative_path" ]]; then
        mkdir -p "$relative_path"
        directories_created_count=$((directories_created_count + 1))
        echo "Created Directory $relative_path"

        #Start Adding Logs to files.
        echo "$name,$relative_path,$type,$size" >> "$DOWNLOADED_LOG_FILE"
      fi
    fi
  done <<< "$csvData"

  # Status Feedback - Once execution is over.
  echo "Downloaded: $files_copied_count, Failed: $files_failed_count, directories Created: $directories_created_count "
  echo "Finished."
}

# Function to compare logs
compare_logs() {
  if [[ -z "$LOG_FILE_A" || -z "$LOG_FILE_B" ]]; then
    echo "Error: --log-file-a and --log-file-b are required for the compare command."
    usage
    exit 1
  fi

  # Check for correct number of arguments
  if [ ! -f "$LOG_FILE_A" ]; then
    echo "Error: Log file A does not exist: $LOG_FILE_A"
    exit 1
  fi

  if [ ! -f "$LOG_FILE_B" ]; then
    echo "Error: Log file B does not exist: $LOG_FILE_B"
    exit 1
  fi

  # Configuration
  OUTPUT_FILE="${OUTPUT_FILE:-$(generate_unique_filename "log_differences")}" #Use given output file name, or make on

  OUTPUT_HEADER="Name,Relative Path,Type,Size,Source Log"

  # Function to escape commas for CSV output
  csv_escape() {
    sed 's/,/","/g'
  }

  # Create associative arrays (requires Bash 4+)
  declare -A log_a_data
  declare -A log_b_data

  # Read Log A
  skip_header=1
  while IFS=, read -r name relative_path type size; do
    if [[ "$skip_header" -eq 1 ]]; then
      skip_header=0
      continue
    fi
    key="${relative_path}${name}"
    log_a_data["$key"]="$name,$relative_path,$type,$size"
  done < "$LOG_FILE_A"

  # Read Log B
  skip_header=1
  while IFS=, read -r name relative_path type size; do
    if [[ "$skip_header" -eq 1 ]]; then
      skip_header=0
      continue
    fi
    key="${relative_path}${name}"
    log_b_data["$key"]="$name,$relative_path,$type,$size"
  done < "$LOG_FILE_B"

  # Create output file and header
  echo "$OUTPUT_HEADER" > "$OUTPUT_FILE"

  # Find differences
  for key in "${!log_a_data[@]}"; do
    if [[ ! ${log_b_data[$key]} ]]; then
      # Entry only in Log A
      echo "\"$(csv_escape "${log_a_data[$key]}" ),A\"" >> "$OUTPUT_FILE"
    else
       size_a=$(echo "${log_a_data[$key]}" | awk -F, '{print $4}')
       size_b=$(echo "${log_b_data[$key]}" | awk -F, '{print $4}')
       if [[ "$size_a" != "$size_b" ]]; then
            size_diff=$((size_b - size_a))
            echo "\"$(csv_escape "${log_a_data[$key]}" | sed "s/,[^,]*,/,${size_diff},/g") ,Size Diff\""  >> "$OUTPUT_FILE"
       fi
    fi
  done

  for key in "${!log_b_data[@]}"; do
    if [[ ! ${log_a_data[$key]} ]]; then
      # Entry only in Log B
      echo "\"$(csv_escape "${log_b_data[$key]}" ),B\"" >> "$OUTPUT_FILE"
    fi
  done

  echo "Differences written to $OUTPUT_FILE"
}

# ---- MAIN EXECUTION ----
case "$COMMAND" in
  index)
    index_files
    ;;
  download)
    download_files
    ;;
  compare)
    compare_logs
    ;;
  "")
    echo "Error: No command specified."
    usage
    exit 1
    ;;
  *)
    echo "Error: Invalid command: $COMMAND"
    usage
    exit 1
    ;;
esac

exit 0
