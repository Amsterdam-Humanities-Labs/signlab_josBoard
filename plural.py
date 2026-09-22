from pattern.nl import pluralize, singularize, conjugate, INFINITIVE, PRESENT, SG, attributive, predicative

word = "kamers"
plural_form = pluralize(word)
singular_form = singularize(word)
# congjugate_one = conjugate(word)
# congjugate_two = conjugate(word, PRESENT, 2, SG)
predic = predicative(word)
attric = attributive(word)

print(f"The word is: {word}")
print(f"{plural_form}, {singular_form}, {predic}, {attric}")
