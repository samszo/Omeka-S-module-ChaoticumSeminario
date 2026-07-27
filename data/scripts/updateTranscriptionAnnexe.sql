-- Met à jour les annexes d'UNE SEULE transcription (au lieu de tout reconstruire
-- comme createTranscriptionAnnexe.sql), à lancer après une correction de
-- transcription (cf. ChaoticumSeminario\View\Helper\TranscriptionCorrection::run()
-- et ChaoticumSeminarioSql::updateTranscriptionAnnexe()).
--
-- Ne touche pas aux annexes `concepts`/`conferences`/`disques` : une correction
-- ne modifie que le titre, la segmentation et les concepts-mots d'une
-- transcription déjà existante (les nouveaux concepts créés par la correction
-- sont déjà insérés dans `concepts` au moment de leur création, voir
-- TranscriptionCorrection::resolveConceptId() / ChaoticumSeminarioSql::addConcept()).
--
-- "?" est un paramètre positionnel (l'id de la transcription) : ce fichier est
-- exécuté via ChaoticumSeminarioSql::updateTranscriptionAnnexe(), pas
-- directement par un client mysql (remplacer "?" par l'id pour un test manuel).

-- 1) rafraîchit la ligne de l'annexe `transcriptions` (le titre/texte a pu changer)
REPLACE INTO transcriptions (idConf, idFrag, start, end, file, id, texte, agent, idDisque)
SELECT i.id confId,
vFrag.value_resource_id fragId, vFragDeb.value 'deb', vFragEnd.value 'end',
CONCAT("files/original/",vFragMedia.storage_id,".",vFragMedia.extension) 'file',
vTrans.resource_id transId, vTransTitle.value transText, vTransAgent.value agent,
vFragSource.value_resource_id idPiste
FROM item i
inner join value vTrans on vTrans.property_id = 451 and vTrans.value_resource_id = i.id
inner join value vTransTitle on vTransTitle.property_id = 1 and vTransTitle.resource_id = vTrans.resource_id
inner join value vTransAgent on vTransAgent.property_id = 2 and vTransAgent.resource_id = vTrans.resource_id
inner join value vFrag on vFrag.property_id = 531 and vFrag.resource_id = vTrans.resource_id
inner join value vFragDeb on vFragDeb.property_id = 543 and vFragDeb.resource_id = vFrag.value_resource_id
inner join value vFragEnd on vFragEnd.property_id = 524 and vFragEnd.resource_id = vFrag.value_resource_id
inner join media vFragMedia on vFragMedia.id = vFrag.value_resource_id
inner join value vFragSource on vFragSource.property_id = 451 and vFragSource.resource_id = vFrag.value_resource_id
WHERE vTrans.resource_id = ?;

-- 2) supprime les liens concept/timeline existants de cette transcription
-- (une correction peut avoir remplacé, ajouté ou retiré des concepts-mots)
DELETE FROM timeline_concept WHERE idTrans = ?;

-- 3) reconstruit ces liens à partir de l'état actuel de curation:data
-- (id de propriété : 216 = jdc:hasConcept, 543 = oa:start, 524 = oa:end, 404 = lexinfo:confidence)
INSERT INTO timeline_concept (idAnno, idTrans, idConcept, start, end, confidence)
SELECT v.value_annotation_id, ? idTrans, vAnnoCpt.value_resource_id idConcept,
 vAnnoStart.value 'start', vAnnoEnd.value 'end', vAnnoConfi.value confi
FROM value v
inner join value vAnnoCpt on vAnnoCpt.resource_id = v.value_annotation_id and vAnnoCpt.property_id = 216
inner join value vAnnoStart on vAnnoStart.resource_id = v.value_annotation_id and vAnnoStart.property_id = 543
inner join value vAnnoEnd on vAnnoEnd.resource_id = v.value_annotation_id and vAnnoEnd.property_id = 524
inner join value vAnnoConfi on vAnnoConfi.resource_id = v.value_annotation_id and vAnnoConfi.property_id = 404
WHERE v.resource_id = ?;
