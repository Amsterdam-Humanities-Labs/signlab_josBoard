import spacy
from pattern.nl import pluralize, lexeme, conjugate

nlp = spacy.load("nl_core_news_lg")
doc = nlp("zijn")


words = []

def lexeme_fix(i):
    try:
        return lexeme(i)
    except:
        pass

def conjugate_fix(i, *args, **kwargs):
    try:
        return conjugate(i, *args, **kwargs)
    except Exception as e:
        # It's good practice to at least print the error for debugging
        # print(f"Error in conjugate_fix for {i}: {e}")
        pass

for token in doc:
    words.append(token.text)
    # nouns → plurals
    if token.pos_ == "NOUN":
        sing = token.lemma_
        print(f"  plural of {sing!r} → {pluralize(sing)}")
        words.append(sing)
        words.append(pluralize(sing))

    # verbs → all forms + specific forms
    if token.pos_ == "VERB":
        verb = token.lemma_            # ← use the lemma, e.g. "willen"
   
        words.append(verb)
        words.append(lexeme_fix(verb))
        words.append(conjugate_fix(verb,
                                          tense="present",
                                          person=1,
                                          number="singular"))
        words.append(conjugate_fix(verb,
                                          tense="past",
                                          person=3,
                                          number="singular"))
        words.append(conjugate_fix(verb,
                                          tense="past",
                                          aspect="participle"))
        # print("  full paradigm:", lexeme(verb))


print(words)