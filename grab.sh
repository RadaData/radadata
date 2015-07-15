#!/bin/sh
ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 '/usr/bin/mysqldump --add-drop-table radadata | /bin/gzip > /home/ubuntu/radadata_prod.sql.gz'
scp -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180:/home/ubuntu/radadata_prod.sql.gz ~/Downloads/radadata_prod.sql.gz
gunzip < ~/Downloads/radadata_prod.sql.gz | mysql -u root -proot radadata

#ssh -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180 'cd /vol/; tar -zcf downloads.tar.gz downloads'
#scp -i ~/.ssh/AMI-ROOT.pem ubuntu@52.6.63.180:/vol/downloads.tar.gz ~/www/radadata/downloads.tar.gz
#rm -rf ~/www/radadata/downloads
#cd ~/www/radadata/
#tar -zxf downloads.tar.gz