import os
#!/usr/bin/env python3
"""
JB Words Lemma Update Tool

This script processes all words in the jb_woorden table,
looks up their lemmas from hh_words, and updates the lemma field.
"""

import mysql.connector
import sys
from typing import List, Dict, Optional

# Configuration
DB_CONFIG = {
    'host': 'localhost',
    'user': 'user',
    'password': os.environ.get('DB_PASS', ''),
    'database': 'admin_gebarenoverleg'
}

def get_db_connection():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as err:
        print(f"Error connecting to database: {err}")
        sys.exit(1)

def lookup_lemma_for_word(word: str, conn) -> Optional[str]:
    cursor = conn.cursor(dictionary=True)
    
    # Preprocess the word
    if word:
        # 1. Check for parentheses and remove them and their contents
        if '(' in word and ')' in word:
            open_paren_idx = word.find('(')
            word = word[:open_paren_idx].strip()
        
        # 2. Remove everything after and including slash (/)
        if '/' in word:
            word = word.split('/')[0].strip()
        
        # 3. Remove everything after and including colon (:)
        if ':' in word:
            word = word.split(':')[0].strip()
        
        # 4. Replace hyphens with spaces (e.g., "NIET-WILLEN" to "NIET WILLEN")
        if '-' in word:
            word = word.replace('-', ' ')
        
        # 5. Split on whitespace and take the first part if there are multiple words
        if ' ' in word:
            word = word.split()[0]
    
    # First, try exact match
    query = "SELECT lemma FROM hh_words WHERE word = %s LIMIT 1"
    cursor.execute(query, (word,))
    result = cursor.fetchone()
    
    if result and result['lemma']:
        cursor.close()
        return result['lemma']
    
    # If no exact match, try case-insensitive match
    query = "SELECT lemma FROM hh_words WHERE LOWER(word) = LOWER(%s) LIMIT 1" 
    cursor.execute(query, (word,))
    result = cursor.fetchone()
    
    cursor.close()
    return result['lemma'] if result and result['lemma'] else None

def process_jb_words(batch_size=100):
    conn = get_db_connection()
    
    try:
        # Get total count of words with NULL lemma
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT COUNT(*) as total FROM jb_woorden WHERE lemma IS NULL OR lemma = ''")
        total = cursor.fetchone()['total']
        print(f"Processing {total} words with NULL lemma in batches of {batch_size}")
        
        update_cursor = conn.cursor()
        update_sql = "UPDATE jb_woorden SET lemma = %s WHERE id = %s"
        
        offset = 0
        words_processed = 0
        
        while offset < total:
            # Get batch of words with NULL lemma
            query = "SELECT id, woord FROM jb_woorden WHERE lemma IS NULL OR lemma = '' ORDER BY id LIMIT %s OFFSET %s"
            cursor.execute(query, (batch_size, offset))
            words = cursor.fetchall()
            
            if not words:
                break
                
            for word in words:
                if not word['woord']:
                    continue
                    
                lemma = lookup_lemma_for_word(word['woord'], conn)
                update_cursor.execute(update_sql, (lemma, word['id']))
                words_processed += 1
                
                if words_processed % 100 == 0:
                    conn.commit()
                    print(f"Processed {words_processed}/{total} words")
            
            offset += batch_size
            conn.commit()
        
        cursor.close()
        update_cursor.close()
        print(f"Completed processing {words_processed} words")
        
    except Exception as e:
        print(f"Error processing words: {e}")
        conn.rollback()
    finally:
        conn.close()

def main():
    print("Starting lemma update process for jb_woorden...")
    process_jb_words()
    print("Lemma update process completed.")

if __name__ == "__main__":
    main()
