#!/usr/bin/env python3
"""
CSV Comparison Tool
Compares two CSV files based on the 'Zin' column and reports differences
"""

import csv
import sys
from typing import Dict, List, Tuple

def read_csv(filename: str) -> Tuple[List[str], Dict[str, List[str]]]:
    """Read CSV file and return headers and a dictionary with Zin as key"""
    data = {}
    headers = []
    
    with open(filename, 'r', encoding='utf-8') as f:
        reader = csv.reader(f, delimiter=';')
        headers = next(reader)
        
        for row in reader:
            if row and row[0]:  # Ensure row is not empty and has a Zin value
                zin = row[0]
                data[zin] = row[1:] if len(row) > 1 else []
    
    return headers, data

def compare_csv_files(old_file: str, new_file: str):
    """Compare two CSV files and report differences"""
    print(f"Comparing {old_file} with {new_file}\n")
    
    # Read both files
    old_headers, old_data = read_csv(old_file)
    new_headers, new_data = read_csv(new_file)
    
    # Report header changes
    if old_headers != new_headers:
        print("=== HEADER CHANGES ===")
        print(f"Old headers: {'; '.join(old_headers)}")
        print(f"New headers: {'; '.join(new_headers)}")
        print()
    
    # Find all unique Zin values
    all_zin = set(old_data.keys()) | set(new_data.keys())
    
    # Track different types of changes
    deleted_rows = []
    added_rows = []
    modified_rows = []
    
    for zin in sorted(all_zin):
        if zin in old_data and zin not in new_data:
            deleted_rows.append(zin)
        elif zin not in old_data and zin in new_data:
            added_rows.append(zin)
        elif old_data[zin] != new_data[zin]:
            # Only the same Zin but different other columns
            modified_rows.append((zin, old_data[zin], new_data[zin]))
    
    # Report deleted rows
    if deleted_rows:
        print(f"=== DELETED ROWS ({len(deleted_rows)}) ===")
        for zin in deleted_rows[:10]:  # Show first 10
            print(f"- {zin}")
        if len(deleted_rows) > 10:
            print(f"... and {len(deleted_rows) - 10} more")
        print()
    
    # Report added rows
    if added_rows:
        print(f"=== ADDED ROWS ({len(added_rows)}) ===")
        for zin in added_rows[:10]:  # Show first 10
            print(f"+ {zin}")
        if len(added_rows) > 10:
            print(f"... and {len(added_rows) - 10} more")
        print()
    
    # Report modified rows
    if modified_rows:
        print(f"=== MODIFIED ROWS ({len(modified_rows)}) ===")
        for zin, old_cols, new_cols in modified_rows[:20]:  # Show first 20
            print(f"\nZin: {zin}")
            print(f"  Old: {'; '.join(old_cols)}")
            print(f"  New: {'; '.join(new_cols)}")
        if len(modified_rows) > 20:
            print(f"\n... and {len(modified_rows) - 20} more modifications")
        print()
    
    # Summary
    print("=== SUMMARY ===")
    print(f"Total rows in old file: {len(old_data)}")
    print(f"Total rows in new file: {len(new_data)}")
    print(f"Deleted rows: {len(deleted_rows)}")
    print(f"Added rows: {len(added_rows)}")
    print(f"Modified rows: {len(modified_rows)}")
    print(f"Unchanged rows: {len(all_zin) - len(deleted_rows) - len(added_rows) - len(modified_rows)}")

def main():
    if len(sys.argv) > 2:
        old_file = sys.argv[1]
        new_file = sys.argv[2]
    else:
        # Default files
        old_file = 'zinnen_old.csv'
        new_file = 'zinnen_new.csv'
    
    try:
        compare_csv_files(old_file, new_file)
    except FileNotFoundError as e:
        print(f"Error: File not found - {e}")
        sys.exit(1)
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()