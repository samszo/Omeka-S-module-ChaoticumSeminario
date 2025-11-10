 -- create concepts annexe
INSERT INTO concepts (id, label)
SELECT v.resource_id, v.value
FROM value v 
INNER JOIN resource r ON r.id = v.resource_id AND r.resource_class_id = 381
WHERE v.property_id = 1;

    -- create conference annexe
INSERT INTO conferences (id, titre, created, ref, source,promo,theme,num)
SELECT r.id confId, vTitle.value titre, vDate.value dt, vRef.value ref, vSrc.value src 
, vPromo.value promo, vTheme.value theme, vNum.value num
-- , LENGTH(vTitle.value) nbCar
FROM resource r
inner join value vTitle on vTitle.property_id = 1 and vTitle.resource_id = r.id
inner join value vDate on vDate.property_id = 7 and vDate.resource_id = r.id
inner join value vSrc on vSrc.property_id = 11 and vSrc.resource_id = r.id
inner join value vRef on vRef.property_id = 35 and vRef.resource_id = r.id
inner join value vTheme on vTheme.property_id = 195 and vTheme.resource_id = r.id
inner join value vNum on vNum.property_id = 203 and vNum.resource_id = r.id
inner join value vPromo on vPromo.property_id = 21 and vPromo.resource_id = r.id
WHERE r.resource_class_id = 47;

   -- mettre à jour l'annexe des conférence avec les mots-clefs
UPDATE conferences as cref,  
    (
SELECT c.id, concat('[',group_concat(JSON_OBJECT('id', v.value_resource_id, 'label', mc.value)),']') o
FROM conferences c
inner join value v on v.resource_id = c.id and v.property_id = 3
inner join value mc on mc.resource_id = v.value_resource_id and mc.property_id = 1
group by c.id) as co
SET cref.sujets = co.o
WHERE cref.id = co.id;
--UPDATE conferences set sujets = "[]" where length(sujets)=4;

-- create disque annexe
INSERT INTO disques (id, idConf, uri, face, plage)
SELECT m.id
 , m.item_id confId  
 , vUri.value uri, vFace.value face, vPlage.value plage
FROM media m
inner join value vUri on vUri.property_id = 11 and vUri.resource_id = m.id
inner join value vFace on vFace.property_id = 492 and vFace.resource_id = m.id
inner join value vPlage on vPlage.property_id = 484 and vPlage.resource_id = m.id
WHERE m.extension = "mp3" and vUri.value <> '';

    -- create transcription annexe
 INSERT INTO transcriptions (idConf, idFrag, start, end, file, id, texte, agent, idDisque)
SELECT i.id confId, 
vFrag.value_resource_id fragId,  vFragDeb.value 'deb', vFragEnd.value 'end', CONCAT("files/original/",vFragMedia.storage_id,".",vFragMedia.extension) 'file',
vTrans.resource_id transId, vTransTitle.value transText, vTransAgent.value agent,
vFragSource.value_resource_id idPiste
--  , LENGTH(vTransTitle.value) nbCar
FROM item i
inner join value vTrans on vTrans.property_id = 451 and vTrans.value_resource_id = i.id
inner join value vTransTitle on vTransTitle.property_id = 1 and vTransTitle.resource_id = vTrans.resource_id
inner join value vTransAgent on vTransAgent.property_id = 2 and vTransAgent.resource_id = vTrans.resource_id
inner join value vFrag on vFrag.property_id = 531 and vFrag.resource_id = vTrans.resource_id
inner join value vFragDeb on vFragDeb.property_id = 543 and vFragDeb.resource_id = vFrag.value_resource_id
inner join value vFragEnd on vFragEnd.property_id = 524 and vFragEnd.resource_id = vFrag.value_resource_id
inner join media vFragMedia on vFragMedia.id = vFrag.value_resource_id
inner join value vFragSource on vFragSource.property_id = 451 and vFragSource.resource_id = vFrag.value_resource_id;
--  WHERE vTrans.resource_id = 2059
-- ORDER BY nbCar DESC


   -- create timeline_concept annexe
 INSERT INTO timeline_concept (idAnno, idTrans, idConcept, start, end, confidence)
select v.value_annotation_id,
 t.id idTrans, vAnnoCpt.value_resource_id idConcept,
 vAnnoStart.value 'start', vAnnoEnd.value 'end', vAnnoConfi.value confi
from resource r
inner join transcriptions t on r.id = t.id
inner join value v on v.resource_id = r.id
inner join value vAnnoCpt on vAnnoCpt.resource_id = v.value_annotation_id and vAnnoCpt.property_id = 216
 inner join value vAnnoStart on vAnnoStart.resource_id = v.value_annotation_id and vAnnoStart.property_id = 543
 inner join value vAnnoEnd on vAnnoEnd.resource_id = v.value_annotation_id and vAnnoEnd.property_id = 524
 inner join value vAnnoConfi on vAnnoConfi.resource_id = v.value_annotation_id and vAnnoConfi.property_id = 404
