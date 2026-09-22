import mysql.connector
import re
import os
import requests
import time
import spacy

try:
    from pattern.nl import pluralize, lexeme, conjugate
    PATTERN_AVAILABLE = True
except ImportError:
    print("Warning: pattern library not available. Install with: pip install pattern")
    PATTERN_AVAILABLE = False

nlp = spacy.load("nl_core_news_lg")


def lexeme_fix(word):
    """Safe wrapper for pattern.nl lexeme function"""
    if not PATTERN_AVAILABLE:
        return None
    try:
        return lexeme(word)
    except:
        return None

def conjugate_fix(word, *args, **kwargs):
    """Safe wrapper for pattern.nl conjugate function"""
    if not PATTERN_AVAILABLE:
        return None
    try:
        return conjugate(word, *args, **kwargs)
    except Exception as e:
        return None

def pluralize_fix(word):
    """Safe wrapper for pattern.nl pluralize function"""
    if not PATTERN_AVAILABLE:
        return None
    try:
        return pluralize(word)
    except:
        return None

def generate_word_variations(word):
    """Generate word variations using spaCy and pattern.nl similar to spacyLemma.py"""
    words = []
    
    doc = nlp(word)
    
    for token in doc:
        words.append(token.text)
        
        # nouns → plurals
        if token.pos_ == "NOUN":
            lemma = token.lemma_
            words.append(lemma)
            plural = pluralize_fix(lemma)
            if plural:
                words.append(plural)
        
        # verbs → all forms + specific forms
        if token.pos_ == "VERB":
            verb = token.lemma_
            words.append(verb)
            
            # Add lexeme forms if available
            lexeme_forms = lexeme_fix(verb)
            if lexeme_forms:
                words.append(lexeme_forms)
            
            # Add conjugated forms
            present_1s = conjugate_fix(verb, tense="present", person=1, number="singular")
            if present_1s:
                words.append(present_1s)
                
            past_3s = conjugate_fix(verb, tense="past", person=3, number="singular")
            if past_3s:
                words.append(past_3s)
                
            past_participle = conjugate_fix(verb, tense="past", aspect="participle")
            if past_participle:
                words.append(past_participle)
    
    # Filter out None values and duplicates, return unique words
    # Keep only valid, non-empty strings
    filtered_words = []
    for w in words:
        if w is not None and isinstance(w, str) and w.strip():
            filtered_words.append(w.strip())
    
    # Remove duplicates while preserving order
    unique_words = []
    seen = set()
    for w in filtered_words:
        if w not in seen:
            unique_words.append(w)
            seen.add(w)
    
    return unique_words


def get_unique_words_and_lemmas():
    # Database configuration
    db_config = {
        'host': 'localhost',
        'user': 'user',
        'password': os.environ.get('DB_PASS', ''),
        'database': 'admin_gebarenoverleg'
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
        
        # Process each new unique word using spacyLemma approach
        for word in words_to_process:
            print(f"Processing word: {word}")
            
            # Skip very short words, numbers, or words containing numbers like "3e"
            if len(word) < 2 or word.isdigit() or re.search(r'\d', word):
                print(f"Skipping word: {word} (contains digits or too short)")
                continue

            # Generate word variations using spacyLemma approach
            word_variations = generate_word_variations(word)
            print(f"Generated variations for '{word}': {word_variations}")
            
            if not word_variations:
                print(f"No variations generated for word '{word}'. Skipping.")
                continue
            
            # Get the main lemma from spaCy processing
            doc = nlp(word)
            if not doc or len(doc) == 0:
                print(f"Skipping word: {word} (spaCy processing returned no tokens).")
                continue
                
            main_token = doc[0]
            main_lemma = main_token.lemma_
            
            print(f"Main lemma for '{word}': '{main_lemma}'")
            
            # Insert each variation as a separate word with the main lemma
            words_inserted_count = 0
            for variation in word_variations:
                if not variation or not isinstance(variation, str) or not variation.strip():
                    continue
                    
                try:
                    # Check if this specific word-lemma combination already exists
                    cursor.execute(
                        "SELECT COUNT(*) FROM hh_words WHERE word = %s AND lemma = %s",
                        (variation, main_lemma)
                    )
                    exists = cursor.fetchone()[0] > 0
                    
                    if not exists:
                        cursor.execute(
                            "INSERT INTO hh_words (word, lemma) VALUES (%s, %s)",
                            (variation, main_lemma)
                        )
                        words_inserted_count += 1
                        print(f"  Inserted variation '{variation}' with lemma '{main_lemma}'")
                    else:
                        print(f"  Combination '{variation}' + '{main_lemma}' already exists in hh_words")
                except mysql.connector.Error as err:
                    print(f"Database error inserting variation '{variation}' with lemma '{main_lemma}': {err}")
            
            # Commit after each word's processing
            conn.commit()
            
            print(f"Completed processing '{word}'. Inserted {words_inserted_count} new variations.")
            print("-" * 50)

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
