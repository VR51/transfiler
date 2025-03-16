#!/bin/bash

# Script to compare two CSV log files and output the differences to a new CSV file.

##
# Transfiler: Differ v1.0.0
# Copyight 2025 VR51, Lee Hodson, Gemini Flash 2
# GNU General Public License v3.0
#
# 1. Use this script to compare two file index log files.
# 2. Create an index of server files and directories on Server One and an index of files and directories on Server Two using index_files.sh or index_files.php.
# 3. Place this script on your web server then make it executable i.e. chmod +x compare_logs.sh
# 4. Or as a one-liner wget && chmod +x compare_logs.sh
# 5. Compare two log files. The script accepts the log files as arguments:
# --- Save both log files into the same directory that stores this script then run the script
# --- ./compare_logs.sh log_file_a log_file_b
# 6. The log file is created as log_differences_[YYYYMMDD_HHMMSS].csv
# 7. Read the output file e.g. nano log_differences_*.csv (where the blob (*) represents the date)
# 8. Delete this script and its log files from your web server after use.
###

# Configuration
OUTPUT_FILE="log_differences_$(date +%Y%m%d_%H%M%S).csv"
OUTPUT_HEADER="Name,Relative Path,Type,Size,Source Log"

# Check for correct number of arguments
if [ $# -ne 2 ]; then
  echo "Usage: $0 <log_file_a> <log_file_b>"
  exit 1
fi

LOG_FILE_A="$1"
LOG_FILE_B="$2"

# Check if log files exist
if [ ! -f "$LOG_FILE_A" ]; then
  echo "Error: Log file A does not exist: $LOG_FILE_A"
  exit 1
fi

if [ ! -f "$LOG_FILE_B" ]; then
  echo "Error: Log file B does not exist: $LOG_FILE_B"
  exit 1
fi

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
