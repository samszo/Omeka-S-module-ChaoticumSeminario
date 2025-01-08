import {transcription} from './transcription.js';
import {loader} from './loader.js';
import {auth} from './auth.js';

console.log(oItem);
console.log(oUser);
console.log(assetUrl);
const wait = new loader();
            //initialisation des connexions
const a = new auth({'navbar':false,
            mail:oUser.userMail,
            apiOmk:'../../../api/',
            ident: oUser.apiIdentity,
            key: oUser.apiCredential
        });
a.getUser(u=>{
    console.log(u);
    showTranscription(oItem["o:id"]);
});

function showTranscription(idConf){
    wait.show();
    let url = "ajax?json=1&helper=sql&action=timelineTrans&idConf="+idConf;                               
    d3.json(url).then(function(rs) {
        let t = new transcription({
            'a':a,
            'cont':d3.select("#contentResources"),
            'contParams':d3.select('#contentResourcesParams'),  
            'vals':rs,
            'selectConcepts': []
        })
        wait.hide();
    });

}

console.log('OK module');

