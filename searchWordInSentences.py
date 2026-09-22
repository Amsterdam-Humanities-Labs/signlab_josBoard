import mysql.connector
import json
import re
import os
import requests
import time

def get_unique_words_and_lemmas():
    # Database configuration
    db_config = {
        'host': 'localhost',
        'user': 'user',
        'password': os.environ.get('DB_PASS', ''),
        'database': 'admin_gebarenoverleg'
    }
    
    # OpenRouter API configuration
    API_KEY = os.environ.get('OPENROUTER_API_KEY', '')
    API_URL = "https://openrouter.ai/api/v1/chat/completions"
    HEADERS = {
        "Authorization": f"Bearer {API_KEY}",
        "HTTP-Referer": "https://signcollect.nl",  # Replace with your site URL
        "X-Title": "GebarenOverleg",                  # Replace with your site name
        "Content-Type": "application/json"
    }
    
    try:
        # Connect to the database
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        
        # Query to get all plain_text from hh_index
        query = "SELECT woord FROM jb_woorden"
        cursor.execute(query)
        
        # Process all texts and extract words
        all_words = []
        for (text,) in cursor:
            if text:
                # Split text into words and clean them
                words = re.findall(r'\b\w+\b', text.lower())
                all_words.extend(words)
        
        # Create a set of unique words
        unique_words = sorted(list(set(all_words)))
        print(f"Total unique words found: {len(unique_words)}")
        
        # Get existing words from hh_words table
        cursor.execute("SELECT word FROM hh_words")
        existing_words = {row[0] for row in cursor.fetchall()}
        print(f"Words already in hh_words table: {len(existing_words)}")
        
        # Filter out words that are already in the hh_words table
        words_to_process = [word for word in unique_words if word not in existing_words]
        print(f"Words left to process: {len(words_to_process)}")
        
        # Process each new unique word to get its lemma and associated words
        for word in words_to_process:
            print(f"Processing word: {word}")
            
            # Skip very short words, numbers, or words containing numbers like "3e"
            if len(word) < 2 or word.isdigit() or re.search(r'\d', word):
                print(f"Skipping word: {word} (contains digits or too short)")
                continue

            # Ask AI for the lemma of the word
            payload = {
                "model": "google/gemini-2.5-flash-preview-05-20:thinking",
                "messages": [
                    {
                        "role": "user",
                        "content": f"Given a Dutch lemma, generate semantically related words and categorize them clearly by their grammatical classes. Provide validated examples from Van Dale only, structured as follows: Example Input: lemma: bakker Example Output: {{\"verb\": [\"bakken\", \"gebakken\", \"bakte\"], \"noun\": [\"bakker\", \"bakkers\"]}} Ensure all provided examples are validated as attested Dutch words in Van Dale. Respond only with the JSON object exactly as structured above, without any additional commentary or text. Give the JSON object of this lemma: {word}"
                    }
                ]
            }
            
            # Initialize lemma and associated_words with defaults based on the current word
            current_lemma = word 
            current_associated_words = [word]

            try: # This try block handles the API call and database operations for the current 'word'
                response = requests.post(API_URL, headers=HEADERS, data=json.dumps(payload))
                
                if response.ok:
                    api_response_content = response.json()["choices"][0]["message"]["content"].strip()
                    # print(f"API Response for '{word}': {api_response_content}") # Uncomment for detailed debugging
                    
                    parsed_json_data = None
                    # Attempt to extract JSON if it's wrapped in markdown-like backticks
                    json_match = re.search(r'```json\s*(.*?)\s*```', api_response_content, re.DOTALL)
                    if json_match:
                        json_content_str = json_match.group(1)
                        try:
                            parsed_json_data = json.loads(json_content_str)
                        except json.JSONDecodeError as e:
                            print(f"JSON decode error (from regex extraction) for word '{word}': {e}. Content: {json_content_str}")
                    else:
                        # If no markdown block, try parsing the whole content as JSON
                        try:
                            parsed_json_data = json.loads(api_response_content)
                        except json.JSONDecodeError as e:
                            print(f"JSON decode error (direct parse) for word '{word}': {e}. Content: {api_response_content}")
                    
                    if parsed_json_data and isinstance(parsed_json_data, dict):
                        # Collect all words from all grammatical categories
                        all_api_words = []
                        for category in ['verb', 'noun', 'adjective', 'adverb', 'pronoun', 'preposition']:
                            if category in parsed_json_data and isinstance(parsed_json_data[category], list):
                                all_api_words.extend(parsed_json_data[category])
                        
                        # Clean and ensure all words are non-empty strings
                        cleaned_api_words_list = [str(w).strip() for w in all_api_words if isinstance(w, (str, int, float)) and str(w).strip()]
                        
                        if cleaned_api_words_list:
                            current_lemma = word  # Use the original word as the lemma
                            # Use a set to ensure uniqueness and include the original word
                            temp_associated_words = set(cleaned_api_words_list)
                            temp_associated_words.add(word) # Ensure original input word is included
                            current_associated_words = list(temp_associated_words)
                        else:
                            print(f"API returned grammatical categories for '{word}', but all were empty or invalid after cleaning. Using defaults.")
                            # current_lemma and current_associated_words retain their initial default values (word, [word])
                    else:
                        # This handles cases where parsed_json_data is None, or not a dict
                        print(f"Failed to parse JSON as expected or not in grammatical category format for '{word}'. Using defaults.")
                        # current_lemma and current_associated_words retain their initial default values
                        
                else: # response not ok (API error)
                    print(f"API error for word '{word}': {response.status_code} - {response.text}. Using defaults.")
                    # current_lemma and current_associated_words retain their initial default values

                # Filter one last time to ensure all words for DB insertion are valid, non-empty strings
                final_words_for_db = [aw for aw in current_associated_words if aw and isinstance(aw, str) and aw.strip()]

                if not final_words_for_db:
                    print(f"No valid associated words to insert for original word '{word}' (derived lemma '{current_lemma}'). Skipping DB insertion.")
                else:
                    words_inserted_count = 0
                    for word_to_insert in final_words_for_db:
                        try:
                            cursor.execute(
                                "INSERT IGNORE INTO hh_words (word, lemma) VALUES (%s, %s)",
                                (word_to_insert, current_lemma)
                            )
                            if cursor.rowcount > 0: # Check if a row was actually inserted (not ignored)
                                words_inserted_count += 1
                        except mysql.connector.Error as err:
                            print(f"Database error inserting word '{word_to_insert}' with lemma '{current_lemma}': {err}")
                    
                    # Commit if any words were candidates for insertion (even if all were ignored due to duplicates)
                    # or if new words were actually inserted.
                    if final_words_for_db: # Check if there were any words to process for DB
                        conn.commit()
                        if words_inserted_count > 0:
                            print(f"Processed '{word}'. Lemma: '{current_lemma}'. Inserted {words_inserted_count} new word-lemma pair(s) from {len(final_words_for_db)} candidates.")
                        else:
                            print(f"Processed '{word}'. Lemma: '{current_lemma}'. All {len(final_words_for_db)} candidate word-lemma pair(s) already existed or no new insertions made.")
            
            # Specific exceptions related to the API call or JSON processing for the current word.
            # These allow the loop to continue to the next word if one word fails.
            except requests.exceptions.RequestException as req_err:
                print(f"Network request failed for word '{word}': {req_err}. Skipping this word.")
                # This error will be caught by the outer 'except Exception as e' which has a 'continue'
            except json.JSONDecodeError as json_err: 
                # This catches errors if response.json() fails (e.g. API returns non-JSON)
                print(f"Failed to decode API response to JSON for word '{word}': {json_err}. Skipping this word.")
            # The original broader 'except Exception as e:' will catch other unexpected errors for this word.

        # Close database connection
        cursor.close()
        conn.close()
        
        print("Successfully processed all words and added them to the hh_words table")
        
    except mysql.connector.Error as err:
        print(f"Database error: {err}")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    get_unique_words_and_lemmas()
