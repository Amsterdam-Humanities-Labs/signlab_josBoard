#!/usr/bin/env python3
"""
Lemma Lookup Tool

This script processes all sentences in the database,
extracts each word, finds its lemma from hh_words,
and compiles a lemmaList for each sentence.
"""

import mysql.connector
import json
import os
import sys
import re
from typing import List, Dict, Optional, Tuple

# Add ClientMonitor
sys.path.insert(0, '/home/gomer/pythonCron')
from python_client import ClientMonitor

# Initialize Client Monitor
monitor = ClientMonitor(
    api_url="https://signcollect.nl/client_monitor_api/api.php",
    client_id="convert-zinstring-lemma",
    client_name="Convert ZinString to Lemma",
    description="Converts sentence words to lemmas and updates database",
    heartbeat_interval=3600  # 60 minutes
)

# Configuration
DB_CONFIG = {
        'host': 'localhost',
        'user': 'user',
        'password': os.environ.get('DB_PASS', ''),
        'database': 'admin_gebarenoverleg'
    }
def get_db_connection():
    """Establish and return a database connection"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as err:
        print(f"Error connecting to database: {err}")
        sys.exit(1)

def lookup_lemma_for_word(word: str, conn) -> Optional[str]:
    """Look up the lemma for a given word in the hh_words table"""
    cursor = conn.cursor(dictionary=True)
    
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
    if result and result['lemma']:
        return result['lemma']
    
    # Return the original word if no lemma found
    return word

def get_all_sentences(conn, batch_size=100) -> List[Dict]:
    """Get all sentences from the database in batches"""
    cursor = conn.cursor(dictionary=True)
    
    # Get total count of sentences
    cursor.execute("SELECT COUNT(*) as total FROM sentences")
    total = cursor.fetchone()['total']
    print(f"Processing {total} sentences in batches of {batch_size}")
    
    offset = 0
    while offset < total:
        # Get a batch of sentences
        query = "SELECT ID, zinString FROM sentences ORDER BY ID LIMIT %s OFFSET %s"
        cursor.execute(query, (batch_size, offset))
        sentences = cursor.fetchall()
        
        if not sentences:
            break
            
        yield sentences
        offset += batch_size
        print(f"Processed {offset}/{total} sentences")
    
    cursor.close()

def process_sentences():
    """Process all sentences in the database"""
    conn = get_db_connection()
    
    try:
        # Create cursor for updating sentences
        update_cursor = conn.cursor()
        update_sql = "UPDATE sentences SET lemmaList = %s WHERE ID = %s"
        
        # Process sentences in batches
        sentences_processed = 0
        lemma_count = 0
        
        for sentence_batch in get_all_sentences(conn):
            for sentence in sentence_batch:
                # Skip sentences without zinString
                if not sentence.get('zinString'):
                    continue
                
                # Extract words from the sentence
                zin_string = sentence['zinString']
                words = re.findall(r'\b\w+\b', zin_string.lower())
                
                # Build lemma list
                lemma_list = []
                for word in words:
                    lemma = lookup_lemma_for_word(word, conn)
                    lemma_list.append(lemma)
                    lemma_count += 1
                
                # Create lemmaList JSON
                lemma_json = json.dumps(lemma_list, ensure_ascii=False)
                
                # Update the sentence with lemmaList
                update_cursor.execute(update_sql, (lemma_json, sentence['ID']))
                
                sentences_processed += 1
                if sentences_processed % 100 == 0:
                    conn.commit()
                    print(f"Committed batch: {sentences_processed} sentences processed")
            
            # Commit after each batch
            conn.commit()
        
        update_cursor.close()
        print(f"Completed processing {sentences_processed} sentences with {lemma_count} lemmas")
        return sentences_processed, lemma_count

    except Exception as e:
        print(f"Error processing sentences: {e}")
        conn.rollback()
        raise
    finally:
        conn.close()

def main():
    """Main function to run the script"""
    print("Starting lemma lookup process for all sentences...")
    sentences_processed, lemma_count = process_sentences()
    print("Lemma lookup process completed.")
    return sentences_processed, lemma_count

if __name__ == "__main__":
    try:
        sentences_processed, lemma_count = main()

        # Send success heartbeat with conversion stats
        monitor.send_heartbeat_with_stats(
            status="success",
            message=f"Lemma conversion completed. Sentences: {sentences_processed}, Lemmas: {lemma_count}",
            stats={
                "sentences_processed": sentences_processed,
                "lemmas_converted": lemma_count
            }
        )

    except Exception as e:
        # Send error heartbeat
        monitor.send_heartbeat_with_stats(
            status="error",
            message=f"Lemma conversion failed: {str(e)}",
            stats={"error_type": type(e).__name__}
        )
        raise  # Re-raise to maintain existing error behavior
