# Copione: (tipo, scena, speaker/evento, testo)
S = []
def L(sc, sp, txt, **kw): S.append(dict(type="line", scene=sc, speaker=sp, text=txt, **kw))
def A(sc, ev, dur, **kw): S.append(dict(type="action", scene=sc, event=ev, dur=dur, **kw))
def C(sc, title, dur=3.0): S.append(dict(type="card", scene=sc, title=title, dur=dur))

L(1, "narr", "Tanto tempo fa, nell’antica Grecia, c’era una città che non aveva ancora un nome.")
L(1, "narr", "Due dèi volevano diventare i suoi protettori: Poseidone, dio del mare, e Atena, dea della saggezza.")
L(1, "zeus", "Poseidone, Atena, entrambi volete proteggere questa città. Ma soltanto uno di voi potrà darle il proprio nome!")
L(1, "pos", "Io sono il dio del mare! Farò un dono straordinario agli abitanti!")
L(1, "atena", "Anch’io farò un dono speciale. Un dono utile per tutta la città!")
L(1, "narr", "Zeus decise che avrebbe vinto la divinità capace di offrire il dono più prezioso agli abitanti.")
L(2, "pos", "Guardate tutti! Con il mio tridente farò nascere un dono dalla terra!")
A(2, "trident", 2.8)
L(2, "narr", "Poseidone colpì il terreno con il suo tridente.")
L(2, "narr", "Secondo alcune versioni della leggenda, fece apparire una sorgente d’acqua salata;", event="spring_hl")
L(2, "narr", "in altre, fece apparire un cavallo.", event="horse")
L(2, "ab1", "Che meraviglia! Che dono ci hai portato?")
L(2, "pos", "La mia forza e il potere del mare!")
L(2, "ab2", "È un dono straordinario, ma abbiamo bisogno anche di qualcosa che ci aiuti nella vita di ogni giorno.")
L(3, "atena", "Ora tocca a me! Vi dono un albero speciale: l’olivo!")
A(3, "grow", 4.0)
L(3, "narr", "Dalla terra nacque un piccolo olivo, con le foglie verdi e i rami delicati.")
L(3, "olivo", "Crescerò lentamente e vivrò per tanti anni!")
L(3, "atena", "Il tuo albero darà olive da mangiare e un olio prezioso.")
L(3, "atena", "I suoi rami offriranno ombra e il suo frutto aiuterà gli abitanti a vivere meglio.", event="olives")
L(3, "ab1", "Potremo mangiare le olive!")
L(3, "ab2", "Potremo ricavare l’olio!")
L(3, "ab3", "E ci prenderemo cura di questo albero per le generazioni future!")
L(4, "narr", "Gli abitanti osservarono i due doni.")
L(4, "narr", "Il dono di Poseidone era potente e meraviglioso, ma quello di Atena avrebbe aiutato la città per molti anni.")
L(4, "zeus", "Abitanti della città, quale dono scegliete?")
L(4, "abitanti", "Scegliamo l’olivo di Atena!", event="cheer")
L(4, "zeus", "Atena ha vinto la sfida! Da oggi, questa città si chiamerà Atene, in suo onore.", event="atene")
A(4, "celebrate", 2.4)
L(4, "atena", "Grazie, abitanti di Atene! Ricordate di prendervi cura del vostro olivo.")
L(4, "pos", "Accetto la vostra scelta. Che la città possa prosperare!")
A(5, "kids_enter", 2.2)
L(5, "narr", "Da quel giorno, l’olivo divenne uno dei simboli più importanti di Atene e della Grecia.")
L(5, "narr", "Ancora oggi è apprezzato per i suoi frutti, il suo olio e la sua lunga vita.")
L(5, "b1", "L’olivo ci dona le sue olive!")
L(5, "b2", "Dal suo frutto ricaviamo l’olio!")
L(5, "b3", "È un albero che può vivere per centinaia di anni!")
L(5, "tutti", "Un albero speciale, un dono prezioso,", verse=1)
L(5, "tutti", "l’olivo cresce forte e generoso!", verse=2)
L(5, "tutti", "Proteggiamo la terra con cura e amore,", verse=3)
L(5, "tutti", "e custodiamo la natura nel cuore!", verse=4)
L(6, "narr", "Fine.", event="fine")

# voce: (voce kokoro, velocità, semitoni, preserva formanti)
VOICES = {
  "narr":  ("if_sara",   0.95, 2.5, False),
  "zeus":  ("im_nicola", 0.86, -4.0, False),
  "pos":   ("im_nicola", 0.96, -1.5, False),
  "atena": ("if_sara",   0.90, -1.5, True),
  "ab1":   ("im_nicola", 1.00, 1.5, False),
  "ab2":   ("if_sara",   1.00, 0.8, True),
  "ab3":   ("im_nicola", 1.02, 4.5, False),
  "olivo": ("if_sara",   0.86, 5.0, False),
  "b1":    ("if_sara",   1.00, 4.0, False),
  "b2":    ("im_nicola", 1.00, 6.0, False),
  "b3":    ("if_sara",   0.98, 5.5, False),
}
CHORUS = {"abitanti": ["ab1", "ab2", "ab3", "b1", "b2"], "tutti": ["narr", "b1", "b2", "b3", "ab3", "olivo"]}
