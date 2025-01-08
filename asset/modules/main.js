import {transcription} from './transcription.js';
import {loader} from './loader.js';

console.log(oItem);
const wait = new loader();
showTranscription(oItem["o:id"]);

function showTranscription(idConf){
    wait.show();
    let url = "ajax?json=1&helper=sql&action=timelineTrans&idConf="+idConf;                               
    d3.json(url).then(function(rs) {
        let t = new transcription({
            'cont':d3.select("#contentResources"),
            'contParams':d3.select('#contentResourcesParams'),  
            'vals':rs,
            'selectConcepts': []
        })
        wait.hide();
    });

}

console.log('OK module');

